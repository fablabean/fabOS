<?php

namespace App\Services\Money;

use App\Models\RateCard;
use App\Models\Supply;

/**
 * Cuánto vale un insumo en FabCoins (§12, §14).
 *
 * Hay dos fuentes, en este orden:
 *
 *  1. **Una tarifa propia**, si alguien se la puso. Es la que manda: una
 *     decisión explícita siempre gana sobre un cálculo.
 *  2. **Su costo de compra**, convertido a FabCoins y con margen. Existe para
 *     que los 13 insumos del laboratorio tengan precio desde el primer día sin
 *     que nadie tenga que tarifarlos uno por uno.
 *
 * El margen no es afán de lucro: cubre el desperdicio, el manejo y el hecho de
 * que se vende al detal lo que se compró al por mayor. Como la tasa peso →
 * FabCoin, es un supuesto administrable, y así se muestra.
 */
class PricingService
{
    /** Precio unitario en unidades menores de FabCoin. */
    public function precioDe(Supply $insumo): int
    {
        $tarifa = $this->tarifaDe($insumo);

        if ($tarifa) {
            return (int) $tarifa->price_minor;
        }

        return $this->derivadoDelCosto($insumo);
    }

    /** Si el precio salió de un cálculo y no de una decisión, conviene decirlo. */
    public function esDerivado(Supply $insumo): bool
    {
        return $this->tarifaDe($insumo) === null;
    }

    public function tarifaDe(Supply $insumo): ?RateCard
    {
        return RateCard::vigente()
            ->where('basis', 'unidad')
            ->where('rateable_type', Supply::class)
            ->where('rateable_id', $insumo->id)
            ->first();
    }

    /**
     * Costo en pesos → FabCoins, con margen y redondeado hacia arriba.
     *
     * Hacia arriba a propósito: en insumos baratos, redondear hacia abajo
     * termina regalando el manejo de cada venta pequeña.
     */
    private function derivadoDelCosto(Supply $insumo): int
    {
        if (! $insumo->last_cost) {
            return 0;
        }

        $tasa = max(1, (int) config('fabos.currency.peso_rate'));
        $margen = 1 + (float) config('fabos.currency.retail_margin');
        $unidades = config('fabos.currency.minor_units');

        return (int) ceil($insumo->last_cost * $margen / $tasa * $unidades);
    }
}
