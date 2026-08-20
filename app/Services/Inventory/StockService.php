<?php

namespace App\Services\Inventory;

use App\Models\Supply;
use App\Models\SupplyMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Existencias de insumos (§7).
 *
 * Una sola regla sostiene el servicio: **la existencia se mueve únicamente por
 * movimientos registrados**. Nadie edita `stock` a mano —ni siquiera para
 * corregir—, porque entonces la existencia y su histórico cuentan cosas
 * distintas y ya no se sabe cuál de las dos miente. Corregir es un movimiento
 * de tipo *ajuste*, que queda con su motivo y con quién lo hizo.
 */
class StockService
{
    public function entrada(
        Supply $insumo,
        float $cantidad,
        ?string $memo = null,
        ?Model $referencia = null,
        ?User $quien = null,
    ): SupplyMovement {
        return $this->mover($insumo, 'entrada', abs($cantidad), $memo, $referencia, $quien);
    }

    /**
     * @throws StockException si no alcanza la existencia
     */
    public function salida(
        Supply $insumo,
        float $cantidad,
        ?string $memo = null,
        ?Model $referencia = null,
        ?User $quien = null,
    ): SupplyMovement {
        $cantidad = abs($cantidad);

        if ((float) $insumo->stock < $cantidad) {
            throw new StockException(
                'No hay suficiente ' . $insumo->name . ': quedan '
                . rtrim(rtrim(number_format((float) $insumo->stock, 3, ',', '.'), '0'), ',')
                . ' ' . $insumo->unit . '.'
            );
        }

        return $this->mover($insumo, 'salida', -$cantidad, $memo, $referencia, $quien);
    }

    /**
     * Ajuste tras un conteo físico: se registra la diferencia, no el resultado.
     *
     * El motivo es obligatorio a propósito. Un ajuste sin explicación es
     * indistinguible de una pérdida que nadie quiso reportar.
     */
    public function ajustar(Supply $insumo, float $existenciaReal, string $motivo, ?User $quien = null): ?SupplyMovement
    {
        $diferencia = $existenciaReal - (float) $insumo->stock;

        if (abs($diferencia) < 0.0005) {
            return null;   // el conteo coincide: no hay nada que anotar
        }

        return $this->mover($insumo, 'ajuste', $diferencia, $motivo, null, $quien);
    }

    private function mover(
        Supply $insumo,
        string $tipo,
        float $delta,
        ?string $memo,
        ?Model $referencia,
        ?User $quien,
    ): SupplyMovement {
        return DB::transaction(function () use ($insumo, $tipo, $delta, $memo, $referencia, $quien) {
            // Se bloquea la fila: dos recepciones simultáneas del mismo insumo
            // calcularían el mismo saldo resultante y una pisaría a la otra.
            $fresco = Supply::whereKey($insumo->id)->lockForUpdate()->first();
            $saldo = (float) $fresco->stock + $delta;

            $fresco->forceFill(['stock' => $saldo])->save();
            $insumo->setAttribute('stock', $saldo);

            return SupplyMovement::create([
                'supply_id'      => $fresco->id,
                'kind'           => $tipo,
                'quantity'       => $delta,
                'balance_after'  => $saldo,
                'reference_type' => $referencia ? $referencia::class : null,
                'reference_id'   => $referencia?->getKey(),
                'created_by'     => $quien?->id,
                'memo'           => $memo,
            ]);
        });
    }
}
