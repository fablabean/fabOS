<?php

namespace App\Services\Shop;

use App\Models\ProductionJob;
use App\Models\Supply;
use App\Models\User;
use App\Services\Inventory\StockException;
use App\Services\Inventory\StockService;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * La cola de producción (§14).
 *
 * Un profesor que necesita cuarenta piezas para una clase no se va a certificar
 * en corte láser: quiere entregar un archivo y recoger las piezas. Eso es un
 * encargo, y sin un sitio donde vivan se acumulan en el WhatsApp de quien
 * coordina hasta que alguno se pierde.
 *
 *   solicitado → cotizado → aceptado → en cola → en producción → listo → entregado
 *
 * Tres reglas:
 *
 *  1. **Se cotiza antes de producir.** Quien pide acepta un precio y un plazo;
 *     el laboratorio no gasta material sobre un «sí, hágamelo».
 *  2. **La cotización se congela al aceptarla.** Cambiar el precio a mitad del
 *     trabajo es exactamente lo que hace que nadie vuelva a encargar nada.
 *  3. **Entregar genera una venta** por el valor cotizado, y saca del inventario
 *     el material gastado sin volver a cobrarlo: la cotización ya lo incluía.
 *     El cobro pasa por el mostrador, para no tener dos formas de mover saldo.
 */
class ProductionService
{
    public function __construct(
        private ShopService $tienda,
        private StockService $existencias,
        private NotificationService $avisos,
    ) {}

    /** Alguien pide un trabajo. Todavía no compromete nada. */
    public function pedir(User $quien, array $datos): ProductionJob
    {
        return ProductionJob::create(array_merge([
            'code'     => $this->siguienteCodigo(),
            'user_id'  => $quien->id,
            'status'   => 'solicitado',
            'priority' => 'normal',
            'quantity' => 1,
        ], $datos));
    }

    /**
     * Cotiza: precio, tiempo estimado y fecha prometida.
     *
     * @throws ShopException
     */
    public function cotizar(
        ProductionJob $encargo,
        int $totalMenor,
        ?int $minutos = null,
        ?string $fecha = null,
        ?string $notas = null,
    ): ProductionJob {
        if (! in_array($encargo->status, ['solicitado', 'cotizado'], true)) {
            throw new ShopException('Este encargo ya pasó de la cotización.');
        }

        if ($totalMenor <= 0) {
            throw new ShopException('La cotización tiene que tener un valor.');
        }

        $encargo->update([
            'status'             => 'cotizado',
            'quoted_total_minor' => $totalMenor,
            'quoted_minutes'     => $minutos,
            'quote_notes'        => $notas,
            'due_on'             => $fecha,
            'quoted_at'          => now(),
        ]);

        $this->avisos->enviar('encargo.cotizado', $encargo->user, [
            'codigo'  => $encargo->code,
            'trabajo' => $encargo->title,
            'valor'   => number_format($totalMenor / config('fabos.currency.minor_units'), 2, ',', '.'),
            'fecha'   => $fecha ? $encargo->fresh()->due_on->format('d/m/Y') : 'por confirmar',
            'notas'   => $notas ?? '',
        ], $encargo);

        return $encargo->refresh();
    }

    /**
     * Quien pide acepta la cotización y el encargo entra a la cola.
     *
     * @throws ShopException
     */
    public function aceptar(ProductionJob $encargo): ProductionJob
    {
        if ($encargo->status !== 'cotizado') {
            throw new ShopException('Solo se acepta un encargo ya cotizado.');
        }

        $encargo->update([
            'status'      => 'en_cola',
            'accepted_at' => now(),
        ]);

        return $encargo->refresh();
    }

    public function rechazar(ProductionJob $encargo, string $motivo): ProductionJob
    {
        if (! $encargo->estaAbierto()) {
            throw new ShopException('Ese encargo ya está cerrado.');
        }

        $encargo->update(['status' => 'rechazado', 'rejection_reason' => $motivo]);

        return $encargo->refresh();
    }

    /** Alguien lo toma y empieza a producirlo. */
    public function iniciar(ProductionJob $encargo, User $quien): ProductionJob
    {
        if (! in_array($encargo->status, ['en_cola', 'aceptado'], true)) {
            throw new ShopException('Este encargo no está en la cola.');
        }

        $encargo->update([
            'status'      => 'en_produccion',
            'assigned_to' => $quien->id,
            'started_at'  => now(),
        ]);

        return $encargo->refresh();
    }

    /** Terminado y listo para recoger. */
    public function terminar(ProductionJob $encargo): ProductionJob
    {
        if ($encargo->status !== 'en_produccion') {
            throw new ShopException('Este encargo no está en producción.');
        }

        $encargo->update(['status' => 'listo', 'finished_at' => now()]);

        $this->avisos->enviar('encargo.listo', $encargo->user, [
            'codigo'  => $encargo->code,
            'trabajo' => $encargo->title,
            'valor'   => number_format($encargo->total(), 2, ',', '.'),
        ], $encargo);

        return $encargo->refresh();
    }

    /**
     * Entrega: descuenta el material, genera la venta y cobra.
     *
     * **El material no se vuelve a cobrar.** La cotización ya lo incluía —así se
     * le presentó a quien pidió—, de modo que aquí solo sale del inventario.
     * Cobrarlo otra vez como línea de venta sería cobrar dos veces el mismo
     * acrílico, y quien encarga lo notaría en la primera factura.
     *
     * Lo que sí reusa el mostrador es el cobro: una sola forma de mover saldo,
     * en vez de dos que tarde o temprano se contradicen.
     *
     * @param  array<int,float>  $materiales  id del insumo => cantidad gastada
     *
     * @throws ShopException
     */
    public function entregar(ProductionJob $encargo, array $materiales = [], ?User $atiende = null): ProductionJob
    {
        if ($encargo->status !== 'listo') {
            throw new ShopException('Solo se entrega un encargo terminado.');
        }

        return DB::transaction(function () use ($encargo, $materiales, $atiende) {
            foreach ($materiales as $insumoId => $cantidad) {
                $cantidad = (float) $cantidad;
                $insumo = $cantidad > 0 ? Supply::find($insumoId) : null;

                if (! $insumo) {
                    continue;
                }

                try {
                    $this->existencias->salida(
                        $insumo,
                        $cantidad,
                        'Encargo ' . $encargo->code,
                        $encargo,
                        $atiende,
                    );
                } catch (StockException $e) {
                    throw new ShopException($e->getMessage());
                }
            }

            $venta = $this->tienda->abrirVenta($encargo->user, $atiende);

            // El servicio va al precio cotizado, no recalculado: es lo que la
            // persona aceptó, y cambiarlo al entregar sería cambiar el trato.
            $this->tienda->agregarServicio(
                $venta,
                $encargo->title . ' (' . $encargo->code . ')',
                1,
                $encargo->quoted_total_minor,
            );

            $pagada = $this->tienda->cobrar($venta->refresh(), $atiende);

            $encargo->update([
                'status'       => 'entregado',
                'sale_id'      => $pagada->id,
                'delivered_at' => now(),
            ]);

            return $encargo->refresh();
        });
    }

    public function cancelar(ProductionJob $encargo, ?string $motivo = null): ProductionJob
    {
        if ($encargo->status === 'entregado') {
            throw new ShopException('Un encargo entregado no se cancela.');
        }

        $encargo->update(['status' => 'cancelado', 'rejection_reason' => $motivo]);

        return $encargo->refresh();
    }

    /**
     * La cola, en el orden en que conviene trabajarla.
     *
     * Primero lo vencido, luego lo urgente, luego lo más antiguo. Ordenar por
     * fecha de pedido a secas dejaría lo prometido para mañana detrás de algo
     * que nadie espera.
     *
     * @return Collection<int,ProductionJob>
     */
    public function cola(): Collection
    {
        return ProductionJob::query()
            ->whereIn('status', ProductionJob::EN_COLA)
            ->with(['user', 'assignedTo', 'asset'])
            ->get()
            ->sortBy([
                fn (ProductionJob $a, ProductionJob $b) => $b->estaVencido() <=> $a->estaVencido(),
                fn (ProductionJob $a, ProductionJob $b) => $this->peso($b->priority) <=> $this->peso($a->priority),
                fn (ProductionJob $a, ProductionJob $b) => ($a->due_on ?? $a->created_at) <=> ($b->due_on ?? $b->created_at),
            ])
            ->values();
    }

    private function peso(string $prioridad): int
    {
        return match ($prioridad) {
            'alta'  => 3,
            'baja'  => 1,
            default => 2,
        };
    }

    /** ENC-2026-0001: legible por teléfono y ordenable. */
    public function siguienteCodigo(): string
    {
        $ano = now(config('fabos.lab.timezone'))->year;
        $ultimo = ProductionJob::where('code', 'like', "ENC-{$ano}-%")->max('code');

        return sprintf('ENC-%d-%04d', $ano, $ultimo ? ((int) substr($ultimo, -4)) + 1 : 1);
    }
}
