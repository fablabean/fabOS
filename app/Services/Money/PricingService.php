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

    /**
     * El precio de venta al público **en pesos**, si alguien lo decidió.
     *
     * Nulo significa que nadie lo ha puesto y la tienda está estimando. Se
     * devuelve en pesos porque es en pesos como se piensa un precio de venta:
     * pedirle a quien tarifa que traduzca a FabCoins de cabeza es pedirle que
     * se equivoque.
     */
    public function precioEnPesosDe(Supply $insumo): ?int
    {
        $tarifa = $this->tarifaDe($insumo);

        return $tarifa ? $this->aPesos((int) $tarifa->price_minor) : null;
    }

    public function aPesos(int $menor): int
    {
        return (int) round($menor / (int) config('fabos.currency.minor_units') * $this->tasa());
    }

    public function aMenor(int $pesos): int
    {
        return (int) round($pesos / $this->tasa() * (int) config('fabos.currency.minor_units'));
    }

    /**
     * Fija —o retira— el precio de venta al público de un insumo.
     *
     * Escribe la **tarifa**, que es lo que ya leen el carrito, la venta de
     * mostrador y el costeo de un proyecto. Guardar el precio en otro sitio
     * dejaría dos números para lo mismo, y el día que difieran nadie sabría
     * cuál cobra de verdad.
     *
     * Vaciarlo no borra la tarifa: la desactiva. Así queda el rastro de que
     * hubo un precio y de cuál era, que es justo lo que se pregunta cuando un
     * cliente reclama lo que le cobraron el mes pasado.
     */
    public function fijarPrecioEnPesos(Supply $insumo, ?int $pesos): ?RateCard
    {
        $slug = 'insumo-' . $insumo->id;
        $tarifa = $this->tarifaDe($insumo) ?? RateCard::where('slug', $slug)->first();

        if (! $pesos || $pesos <= 0) {
            $tarifa?->update(['is_active' => false]);

            return null;
        }

        $valores = [
            'name'          => 'Venta al público · ' . $insumo->name,
            'rateable_type' => Supply::class,
            'rateable_id'   => $insumo->id,
            'basis'         => 'unidad',
            'unit'          => $insumo->unit,
            'price_minor'   => $this->aMenor($pesos),
            'is_active'     => true,
            // Lo puso una persona: no es un supuesto pendiente de decidir.
            'is_assumed'    => false,
            'notes'         => 'Se fija desde la ficha del insumo.',
        ];

        if ($tarifa) {
            $tarifa->update($valores);

            return $tarifa;
        }

        return RateCard::create($valores + ['slug' => $slug]);
    }

    private function tasa(): int
    {
        return max(1, (int) config('fabos.currency.peso_rate'));
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
