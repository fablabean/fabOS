<?php

namespace App\Services\Purchasing;

use App\Models\Budget;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\Supply;
use App\Models\User;
use App\Services\Inventory\StockService;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * El camino de una compra (§13).
 *
 *   carrito → enviada → aprobada → en compra → recibida
 *
 * Cada paso existe porque responde a alguien distinto: el carrito es de quien
 * necesita, el envío pone fecha, la aprobación compromete presupuesto y la
 * recepción mete las cosas al inventario. Saltarse uno es justo lo que hace que
 * a fin de año nadie sepa en qué se fue la plata.
 */
class PurchasingService
{
    public function __construct(
        private StockService $existencias,
        private NotificationService $avisos,
    ) {}

    /** Abre un carrito. Todavía no compromete nada. */
    public function abrirCarrito(User $quien, ?Budget $presupuesto = null, ?string $justificacion = null): PurchaseRequest
    {
        return PurchaseRequest::create([
            'code'          => $this->siguienteCodigo(),
            'budget_id'     => $presupuesto?->id,
            'area_id'       => $presupuesto?->area_id,
            'requested_by'  => $quien->id,
            'status'        => 'borrador',
            'justification' => $justificacion,
        ]);
    }

    /**
     * Añade una línea. Si repone un insumo conocido, hereda su unidad y su
     * último costo: pedir filamento no debería obligar a buscar la factura
     * anterior para saber cuánto costaba.
     *
     * @throws PurchasingException si la solicitud ya no admite cambios
     */
    public function agregar(
        PurchaseRequest $solicitud,
        string $descripcion,
        float $cantidad,
        ?int $precioUnitario = null,
        ?Supply $insumo = null,
        ?string $unidad = null,
        ?string $proveedor = null,
        ?string $enlace = null,
    ): PurchaseRequestItem {
        $this->exigirEditable($solicitud);

        if ($cantidad <= 0) {
            throw new PurchasingException('La cantidad debe ser mayor que cero.');
        }

        return $solicitud->items()->create([
            'supply_id'     => $insumo?->id,
            'description'   => $descripcion,
            'unit'          => $unidad ?? $insumo?->unit ?? 'unidad',
            'quantity'      => $cantidad,
            'unit_price'    => $precioUnitario ?? $insumo?->last_cost ?? 0,
            'supplier'      => $proveedor,
            'reference_url' => $enlace,
        ]);
    }

    /** Sugiere el carrito de reposición: todo lo que está bajo mínimos. */
    public function llenarConLoQueFalta(PurchaseRequest $solicitud): int
    {
        $this->exigirEditable($solicitud);

        $faltantes = Supply::where('is_active', true)
            ->whereNotNull('reorder_point')
            ->whereColumn('stock', '<=', 'reorder_point')
            ->get();

        foreach ($faltantes as $insumo) {
            // Se pide el doble del punto de reposición menos lo que hay: repone
            // y deja colchón, en vez de dejarlo justo en el límite otra vez.
            $cantidad = max(1, (float) $insumo->reorder_point * 2 - (float) $insumo->stock);

            $this->agregar(
                $solicitud,
                $insumo->name,
                round($cantidad, 3),
                $insumo->last_cost,
                $insumo,
            );
        }

        return $faltantes->count();
    }

    /**
     * Envía la solicitud. A partir de aquí queda con fecha y deja de ser un
     * borrador que alguien puede ir cambiando sin que se note.
     *
     * @throws PurchasingException
     */
    public function enviar(PurchaseRequest $solicitud): PurchaseRequest
    {
        if ($solicitud->status !== 'borrador') {
            throw new PurchasingException('Esta solicitud ya fue enviada.');
        }

        if ($solicitud->items()->count() === 0) {
            throw new PurchasingException('No se puede enviar una solicitud sin líneas.');
        }

        $solicitud->update(['status' => 'enviada', 'submitted_at' => now()]);

        return $solicitud->refresh();
    }

    /**
     * Aprueba y compromete presupuesto.
     *
     * Se comprueba el disponible: aprobar por encima del presupuesto no es un
     * detalle contable, es una compra que después no se puede pagar.
     *
     * @throws PurchasingException
     */
    public function aprobar(PurchaseRequest $solicitud, User $quien, ?Budget $presupuesto = null): PurchaseRequest
    {
        if (! in_array($solicitud->status, ['enviada', 'borrador'], true)) {
            throw new PurchasingException('Solo se aprueban solicitudes enviadas.');
        }

        $presupuesto ??= $solicitud->budget;

        if (! $presupuesto) {
            throw new PurchasingException('Hay que decir contra qué presupuesto se aprueba.');
        }

        if ($presupuesto->status !== 'vigente') {
            throw new PurchasingException('Ese presupuesto no está vigente.');
        }

        $solicitud->load('items');
        $costo = $solicitud->totalEstimado();

        if ($costo > $presupuesto->disponible()) {
            throw new PurchasingException(sprintf(
                'No alcanza el presupuesto: la solicitud vale %s y quedan %s.',
                $this->enPesos($costo),
                $this->enPesos($presupuesto->disponible()),
            ));
        }

        $solicitud->update([
            'status'      => 'aprobada',
            'budget_id'   => $presupuesto->id,
            'approved_by' => $quien->id,
            'decided_at'  => now(),
        ]);

        $this->avisarDecision($solicitud, 'aprobada');

        return $solicitud->refresh();
    }

    public function rechazar(PurchaseRequest $solicitud, User $quien, string $motivo): PurchaseRequest
    {
        if (in_array($solicitud->status, PurchaseRequest::CERRADAS, true)) {
            throw new PurchasingException('Esa solicitud ya está cerrada.');
        }

        $solicitud->update([
            'status'          => 'rechazada',
            'approved_by'     => $quien->id,
            'decided_at'      => now(),
            'decision_reason' => $motivo,
            'closed_at'       => now(),
        ]);

        $this->avisarDecision($solicitud, 'rechazada', $motivo);

        return $solicitud->refresh();
    }

    /** Marca que compras ya tramitó la orden. Sigue comprometiendo presupuesto. */
    public function marcarEnCompra(PurchaseRequest $solicitud): PurchaseRequest
    {
        if ($solicitud->status !== 'aprobada') {
            throw new PurchasingException('Solo pasa a compra lo que está aprobado.');
        }

        $solicitud->update(['status' => 'en_compra']);

        return $solicitud->refresh();
    }

    /**
     * Recibe lo pedido, entero o por partes.
     *
     * **No siempre es mercancía.** Por aquí pasan también unos honorarios o un
     * curso contratado: se reciben igual —se dan por cumplidos y ejecutan el
     * presupuesto— pero no tienen insumo detrás y no tocan inventario.
     *
     * Lo que sí está enlazado a un insumo entra al inventario en el mismo acto:
     * si la entrada al stock fuera un segundo paso manual, la existencia
     * quedaría desfasada justo cuando más se consulta.
     *
     * @param  array<int,float>  $recibido  id de la línea => cantidad que llegó ahora
     *
     * @throws PurchasingException
     */
    public function recibir(PurchaseRequest $solicitud, array $recibido, User $quien, ?string $memo = null): PurchaseRequest
    {
        if (! in_array($solicitud->status, ['aprobada', 'en_compra', 'recibida_parcial'], true)) {
            throw new PurchasingException('Esta solicitud no está en condición de recibir nada todavía.');
        }

        return DB::transaction(function () use ($solicitud, $recibido, $quien, $memo) {
            foreach ($solicitud->items as $linea) {
                $cantidad = (float) ($recibido[$linea->id] ?? 0);

                if ($cantidad <= 0) {
                    continue;
                }

                if ($cantidad > $linea->pendiente() + 0.0005) {
                    throw new PurchasingException(
                        'Llegó más de lo pedido en «' . $linea->description . '». '
                        . 'Si de verdad llegó de más, ajústalo en la línea antes de recibir.'
                    );
                }

                $linea->increment('received_quantity', $cantidad);

                if ($linea->supply) {
                    $this->existencias->entrada(
                        $linea->supply,
                        $cantidad,
                        'Recepción ' . $solicitud->code,
                        $solicitud,
                        $quien,
                    );

                    // El último costo conocido sirve para la próxima compra.
                    if ($linea->unit_price > 0) {
                        $linea->supply->forceFill(['last_cost' => $linea->unit_price])->save();
                    }
                }
            }

            $solicitud->load('items');
            $completa = $solicitud->estaCompleta();

            $solicitud->update([
                'status'    => $completa ? 'recibida' : 'recibida_parcial',
                'closed_at' => $completa ? now() : null,
                'notes'     => $memo ? trim($solicitud->notes . "\n" . $memo) : $solicitud->notes,
            ]);

            return $solicitud->refresh();
        });
    }

    public function cancelar(PurchaseRequest $solicitud, string $motivo): PurchaseRequest
    {
        if ($solicitud->status === 'recibida') {
            throw new PurchasingException('Una solicitud ya recibida no se cancela.');
        }

        $solicitud->update([
            'status'          => 'cancelada',
            'decision_reason' => $motivo,
            'closed_at'       => now(),
        ]);

        return $solicitud->refresh();
    }

    /**
     * Comparte la requisición: un enlace sin sesión que compras abre y baja
     * en PDF.
     *
     * Se genera una sola vez: compartir dos veces devuelve el mismo enlace, que
     * es lo que espera quien ya lo mandó por correo. Y no congela nada: si
     * después se corrige una cantidad, compras ve la corrección en el mismo
     * enlace, en vez de quedarse con el PDF viejo adjunto en un correo.
     */
    public function compartir(PurchaseRequest $solicitud): string
    {
        if (! $solicitud->share_token) {
            $solicitud->update([
                'share_token' => Str::random(40),
                'shared_at'   => now(),
            ]);
        }

        return $solicitud->enlaceCompartido();
    }

    /** Revoca el enlace: el que ya se mandó deja de abrir, y compartir de nuevo da otro. */
    public function dejarDeCompartir(PurchaseRequest $solicitud): void
    {
        $solicitud->update(['share_token' => null, 'shared_at' => null]);
    }

    /**
     * Avisa la decisión a quien pidió.
     *
     * Es esencial: quien pidió algo y no se entera de que se lo negaron vuelve
     * a preguntar, o peor, da por hecho que va en camino.
     */
    private function avisarDecision(PurchaseRequest $solicitud, string $estado, ?string $motivo = null): void
    {
        if (! $solicitud->requestedBy) {
            return;
        }

        $this->avisos->enviar('compra.decidida', $solicitud->requestedBy, [
            'codigo' => $solicitud->code,
            'estado' => $estado,
            'motivo' => $motivo ?? '',
        ], $solicitud);
    }

    /** COM-2026-0001: legible por teléfono, ordenable y único por año. */
    private function siguienteCodigo(): string
    {
        $ano = now(config('fabos.lab.timezone'))->year;
        $ultimo = PurchaseRequest::where('code', 'like', "COM-{$ano}-%")->max('code');
        $consecutivo = $ultimo ? ((int) substr($ultimo, -4)) + 1 : 1;

        return sprintf('COM-%d-%04d', $ano, $consecutivo);
    }

    private function exigirEditable(PurchaseRequest $solicitud): void
    {
        if (! $solicitud->esEditable()) {
            throw new PurchasingException(
                'Esta solicitud está ' . (PurchaseRequest::ESTADOS[$solicitud->status] ?? $solicitud->status)
                . ' y ya no admite cambios en las líneas.'
            );
        }
    }

    private function enPesos(int $pesos): string
    {
        return config('fabos.money.symbol') . number_format($pesos, 0, ',', '.');
    }
}
