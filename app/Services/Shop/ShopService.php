<?php

namespace App\Services\Shop;

use App\Models\LedgerAccount;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Supply;
use App\Models\User;
use App\Services\Inventory\StockException;
use App\Services\Inventory\StockService;
use App\Services\Ledger\LedgerException;
use App\Services\Ledger\LedgerService;
use App\Services\Money\PricingService;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * El mostrador (§14).
 *
 * Cobrar hace tres cosas que tienen que pasar juntas o no pasar: mueve el saldo,
 * descuenta la existencia y congela el precio. Si alguna fallara por separado
 * quedaría una venta cobrada sin entregar, o entregada sin cobrar, y ninguna de
 * las dos se descubre hasta el cierre de mes. Por eso van en una transacción.
 *
 * El cobro puede estar apagado —mientras la tarifa ancla no esté decidida—. En
 * ese caso la venta se registra y la existencia sí se mueve: el mostrador se
 * puede ensayar completo sin que el dinero sea real todavía.
 */
class ShopService
{
    public function __construct(
        private LedgerService $libro,
        private StockService $existencias,
        private PricingService $precios,
    ) {}

    public function abrirVenta(User $cliente, ?User $atiende = null): Sale
    {
        return Sale::create([
            'code'      => $this->siguienteCodigo(),
            'user_id'   => $cliente->id,
            'served_by' => $atiende?->id,
            'status'    => 'abierta',
        ]);
    }

    /**
     * Añade un insumo. El precio se toma del catálogo en el momento de añadirlo
     * y ya no se mueve: la persona ve un total que no cambia mientras decide.
     *
     * @throws ShopException
     */
    public function agregarInsumo(Sale $venta, Supply $insumo, float $cantidad, ?int $precioMenor = null): SaleItem
    {
        $this->exigirAbierta($venta);

        if ($cantidad <= 0) {
            throw new ShopException('La cantidad debe ser mayor que cero.');
        }

        $precio = $precioMenor ?? $this->precios->precioDe($insumo);

        if ($precio <= 0) {
            throw new ShopException(
                $insumo->name . ' no tiene precio. Ponle una tarifa o registra su costo de compra.'
            );
        }

        return $venta->items()->create([
            'supply_id'        => $insumo->id,
            'description'      => $insumo->name,
            'unit'             => $insumo->unit,
            'quantity'         => $cantidad,
            'unit_price_minor' => $precio,
        ]);
    }

    /** Añade un servicio especial: no toca inventario. */
    public function agregarServicio(Sale $venta, string $descripcion, float $cantidad, int $precioMenor): SaleItem
    {
        $this->exigirAbierta($venta);

        if ($cantidad <= 0 || $precioMenor <= 0) {
            throw new ShopException('Un servicio necesita cantidad y precio mayores que cero.');
        }

        return $venta->items()->create([
            'description'      => $descripcion,
            'unit'             => 'servicio',
            'quantity'         => $cantidad,
            'unit_price_minor' => $precioMenor,
        ]);
    }

    /**
     * Cobra la venta: saldo, existencia y precio congelado, todo junto.
     *
     * @throws ShopException si no hay líneas, no alcanza el saldo o falta insumo
     */
    public function cobrar(Sale $venta, ?User $atiende = null): Sale
    {
        $this->exigirAbierta($venta);
        $venta->load(['items.supply', 'user']);

        if ($venta->items->isEmpty()) {
            throw new ShopException('No se puede cobrar una venta sin líneas.');
        }

        $total = $venta->totalMenor();

        return DB::transaction(function () use ($venta, $atiende, $total) {
            // Primero la existencia: si falta material, es mejor descubrirlo
            // antes de haber tocado el saldo de nadie.
            foreach ($venta->items as $linea) {
                if (! $linea->mueveInventario()) {
                    continue;
                }

                try {
                    $this->existencias->salida(
                        $linea->supply,
                        (float) $linea->quantity,
                        'Venta ' . $venta->code,
                        $venta,
                        $atiende,
                    );
                } catch (StockException $e) {
                    throw new ShopException($e->getMessage());
                }
            }

            if (Settings::cobrosActivos() && $total > 0) {
                $cuenta = $this->libro->cuentaDe($venta->user);

                if ($cuenta->saldoMenor() < $total) {
                    throw new ShopException(sprintf(
                        'A %s no le alcanza el saldo: la venta vale %s y tiene %s.',
                        $venta->user->name,
                        $this->enFabcoins($total),
                        $this->enFabcoins($cuenta->saldoMenor()),
                    ));
                }

                try {
                    $this->libro->transferir(
                        $cuenta,
                        $this->libro->cuentaDeSistema(LedgerAccount::INGRESO),
                        $total,
                        'venta',
                        'Venta ' . $venta->code,
                        'venta:' . $venta->id,
                        $venta,
                        $atiende,
                    );
                } catch (LedgerException $e) {
                    throw new ShopException($e->getMessage());
                }
            }

            $venta->update([
                'status'      => 'pagada',
                'total_minor' => $total,
                'paid_at'     => now(),
                'served_by'   => $atiende?->id ?? $venta->served_by,
            ]);

            return $venta->refresh();
        });
    }

    /**
     * Anula una venta ya cobrada.
     *
     * No se borra ni se edita: se devuelve el saldo con un movimiento nuevo y la
     * mercancía vuelve al inventario con su propia entrada. El histórico tiene
     * que poder contar que hubo una venta y que se deshizo, no fingir que nunca
     * ocurrió.
     *
     * @throws ShopException
     */
    public function anular(Sale $venta, string $motivo, ?User $quien = null): Sale
    {
        if ($venta->status === 'anulada') {
            throw new ShopException('Esa venta ya está anulada.');
        }

        $venta->load(['items.supply', 'user']);

        return DB::transaction(function () use ($venta, $motivo, $quien) {
            if ($venta->status === 'pagada') {
                foreach ($venta->items as $linea) {
                    if ($linea->mueveInventario()) {
                        $this->existencias->entrada(
                            $linea->supply,
                            (float) $linea->quantity,
                            'Anulación de la venta ' . $venta->code,
                            $venta,
                            $quien,
                        );
                    }
                }

                if ($venta->total_minor > 0 && Settings::cobrosActivos()) {
                    $this->libro->transferir(
                        $this->libro->cuentaDeSistema(LedgerAccount::INGRESO),
                        $this->libro->cuentaDe($venta->user),
                        $venta->total_minor,
                        'devolucion',
                        'Anulación de la venta ' . $venta->code . ': ' . $motivo,
                        'venta:' . $venta->id . ':anulacion',
                        $venta,
                        $quien,
                    );
                }
            }

            $venta->update([
                'status'      => 'anulada',
                'voided_at'   => now(),
                'void_reason' => $motivo,
            ]);

            return $venta->refresh();
        });
    }

    /** Lo que hay para vender: insumos activos con existencia y con precio. */
    public function catalogo(): \Illuminate\Support\Collection
    {
        return Supply::where('is_active', true)
            ->where('stock', '>', 0)
            ->orderBy('name')
            ->get()
            ->map(fn (Supply $s) => [
                'insumo'   => $s,
                'precio'   => $this->precios->precioDe($s),
                'derivado' => $this->precios->esDerivado($s),
            ])
            ->filter(fn (array $fila) => $fila['precio'] > 0)
            ->values();
    }

    private function siguienteCodigo(): string
    {
        $ano = now(config('fabos.lab.timezone'))->year;
        $ultimo = Sale::where('code', 'like', "VTA-{$ano}-%")->max('code');

        return sprintf('VTA-%d-%04d', $ano, $ultimo ? ((int) substr($ultimo, -4)) + 1 : 1);
    }

    private function exigirAbierta(Sale $venta): void
    {
        if (! $venta->esEditable()) {
            throw new ShopException(
                'Esta venta está ' . (Sale::ESTADOS[$venta->status] ?? $venta->status) . ' y ya no se toca.'
            );
        }
    }

    private function enFabcoins(int $menor): string
    {
        return number_format($menor / config('fabos.currency.minor_units'), 2, ',', '.')
            . ' ' . config('fabos.currency.code');
    }
}
