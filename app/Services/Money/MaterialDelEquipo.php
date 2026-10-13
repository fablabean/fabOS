<?php

namespace App\Services\Money;

use App\Models\Area;
use App\Models\Asset;
use App\Models\RateCard;
use App\Models\RiskFamily;
use Illuminate\Support\Collection;

/**
 * El material que gasta un equipo, cobrado por cantidad (§12).
 *
 * Dos casos, y la tarifa dice cuál:
 *
 *  · **Se pide** (`ask_on_booking`). En una impresora lo que cuesta no es la
 *    hora: es el filamento. La tarifa dice «8 FabCoins por cada 60 g», quien
 *    reserva escribe los gramos que le da el laminador, y eso se retiene.
 *  · **Se ofrece** (`offer_on_booking`). En el láser se paga el tiempo, y el
 *    material es una opción: se trae o se compra del que hay. Puede traer un
 *    trozo de regalo por reserva —20 × 20 cm de MDF—.
 *
 * En los dos, al cerrar se confirma lo que se gastó de verdad y se ajusta.
 *
 * Es una tarifa «por unidad» puesta sobre el equipo, su familia de riesgo o su
 * área, **y marcada**. Que exista no basta: hay precios de material que sirven
 * solo para cotizar, y nadie quiere que por eso toda una sala le pregunte
 * «cuánto material» a quien reserva.
 */
class MaterialDelEquipo
{
    /** La que se pide sí o sí al reservar este equipo, si la hay. */
    public static function tarifa(Asset $activo): ?RateCard
    {
        foreach (self::niveles($activo) as [$tipo, $id]) {
            $tarifa = self::delNivel($tipo, $id)->where('ask_on_booking', true)->orderBy('id')->first();

            if ($tarifa) {
                return $tarifa;
            }
        }

        return null;
    }

    /**
     * Las que se ofrecen, a elegir. De todos los niveles a la vez: un equipo
     * puede tener un material propio además de los de su familia.
     *
     * @return Collection<int, RateCard>
     */
    public static function ofrecidas(Asset $activo): Collection
    {
        // Donde el material es obligatorio no hay nada que elegir.
        if (self::tarifa($activo)) {
            return collect();
        }

        return collect(self::niveles($activo))
            ->flatMap(fn (array $nivel) => self::delNivel(...$nivel)->where('offer_on_booking', true)->get())
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /**
     * La tarifa con la que se cobra el material de una reserva de este equipo:
     * la obligatoria si la hay, y si no la que se eligió entre las ofrecidas.
     */
    public static function elegida(Asset $activo, int|string|null $id): ?RateCard
    {
        return self::tarifa($activo)
            ?? ($id ? self::ofrecidas($activo)->firstWhere('id', (int) $id) : null);
    }

    /**
     * Lo que cuesta esa cantidad, en unidades menores.
     *
     * Descontado el regalo, proporcional —30 g son la mitad de 60 g— y
     * redondeado **al FabCoin siguiente**: no se cobran fracciones de FabCoin
     * por un material.
     */
    public static function costo(RateCard $tarifa, float $cantidad): int
    {
        $cantidad -= self::regalo($tarifa);

        if ($cantidad <= 0 || $tarifa->price_minor <= 0) {
            return 0;
        }

        $unidades = (int) config('fabos.currency.minor_units');
        $exacto = $tarifa->price_minor * $cantidad / self::porCada($tarifa);

        // Redondeado antes de subir: 8 × 60 / 60 tiene que dar 8, no 8,0000001
        // y de ahí 9.
        return (int) ceil(round($exacto / $unidades, 6)) * $unidades;
    }

    public static function porCada(RateCard $tarifa): float
    {
        return (float) $tarifa->unit_quantity > 0 ? (float) $tarifa->unit_quantity : 1.0;
    }

    /** Cuánto va de regalo en cada reserva. */
    public static function regalo(RateCard $tarifa): float
    {
        return max(0.0, (float) $tarifa->free_quantity);
    }

    /** «8 FBC por cada 60 g», como se le dice a quien reserva. */
    public static function regla(RateCard $tarifa): string
    {
        $porCada = self::porCada($tarifa);
        $precio = $tarifa->price_minor / config('fabos.currency.minor_units');

        // Un cm² vale milésimas de FabCoin: dicho así no se entiende. Se dice
        // por la cantidad que da un número legible.
        if ($precio > 0 && $precio < 0.1 && $porCada == 1.0) {
            $porCada = 100.0;
            $precio *= 100;
        }

        return self::numero($precio)
            . ' ' . config('fabos.currency.code') . ' por '
            . ($porCada == 1.0 ? '' : 'cada ' . self::numero($porCada) . ' ')
            . ($tarifa->unit ?: 'unidad');
    }

    /** Si se mide en superficie: ahí se pregunta ancho por alto. */
    public static function esSuperficie(RateCard $tarifa): bool
    {
        return (bool) preg_match('/^cm\s*(2|²)$/iu', trim((string) $tarifa->unit));
    }

    public static function numero(float $valor): string
    {
        $texto = number_format($valor, 2, ',', '.');

        return str_contains($texto, ',') ? rtrim(rtrim($texto, '0'), ',') : $texto;
    }

    /** @return array<int, array{0: class-string, 1: int}> */
    private static function niveles(Asset $activo): array
    {
        return array_values(array_filter([
            [Asset::class, $activo->id],
            [RiskFamily::class, $activo->risk_family_id],
            [Area::class, $activo->area_id],
        ], fn (array $nivel) => (bool) $nivel[1]));
    }

    private static function delNivel(string $tipo, int $id)
    {
        return RateCard::vigente()
            ->where('rateable_type', $tipo)
            ->where('rateable_id', $id)
            ->where('basis', 'unidad');
    }
}
