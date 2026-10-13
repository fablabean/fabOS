<?php

namespace App\Services\Money;

use App\Models\Area;
use App\Models\Asset;
use App\Models\RateCard;
use App\Models\RiskFamily;

/**
 * El material que gasta un equipo, cobrado por cantidad (§12).
 *
 * En una impresora lo que cuesta no es la hora: es el filamento. La tarifa
 * dice «8 FabCoins por cada 60 g», quien reserva escribe los gramos que le da
 * el laminador, y eso es lo que se retiene. Al cerrar confirma lo que gastó
 * de verdad y se ajusta la diferencia.
 *
 * Es una tarifa «por unidad» puesta sobre el equipo, su familia de riesgo o su
 * área, **y marcada para pedirse al reservar**. Que exista no basta: el área
 * de láser tiene tres precios de MDF por cm² que sirven para cotizar, y nadie
 * quiere que por eso toda la sala le pregunte «cuánto material» a quien
 * reserva, con el precio de un grosor cualquiera.
 */
class MaterialDelEquipo
{
    public static function tarifa(Asset $activo): ?RateCard
    {
        $candidatos = [
            [Asset::class, $activo->id],
            [RiskFamily::class, $activo->risk_family_id],
            [Area::class, $activo->area_id],
        ];

        foreach ($candidatos as [$tipo, $id]) {
            if (! $id) {
                continue;
            }

            $tarifa = RateCard::vigente()
                ->where('rateable_type', $tipo)
                ->where('rateable_id', $id)
                ->where('basis', 'unidad')
                ->where('ask_on_booking', true)
                ->orderBy('id')
                ->first();

            if ($tarifa) {
                return $tarifa;
            }
        }

        return null;
    }

    /**
     * Lo que cuesta esa cantidad, en unidades menores.
     *
     * Proporcional —30 g son la mitad de 60 g— y redondeado **al FabCoin
     * siguiente**: no se cobran fracciones de FabCoin por un material.
     */
    public static function costo(RateCard $tarifa, float $cantidad): int
    {
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

    /** «8 FBC por cada 60 g», como se le dice a quien reserva. */
    public static function regla(RateCard $tarifa): string
    {
        $porCada = self::porCada($tarifa);

        return self::numero($tarifa->price_minor / config('fabos.currency.minor_units'))
            . ' ' . config('fabos.currency.code') . ' por '
            . ($porCada == 1.0 ? '' : 'cada ' . self::numero($porCada) . ' ')
            . ($tarifa->unit ?: 'unidad');
    }

    public static function numero(float $valor): string
    {
        $texto = number_format($valor, 2, ',', '.');

        return str_contains($texto, ',') ? rtrim(rtrim($texto, '0'), ',') : $texto;
    }
}
