<?php

namespace App\Filament\Resources\Budgets\Widgets;

use App\Models\Budget;
use Filament\Widgets\Widget;

/**
 * El año de un vistazo, encima del listado.
 *
 * Con seis presupuestos separados, saber cuánto queda en total obligaba a
 * sumar seis cifras a mano cada vez que alguien preguntaba. Es justo la clase
 * de cuenta que se hace mal cuando hay prisa.
 *
 * El de **venta** va aparte, no sumado: es una meta de lo que esperamos
 * facturar, no plata que la Universidad haya asignado. Mezclarlos diría que
 * hay diez millones más para gastar de los que hay.
 */
class ResumenDelAno extends Widget
{
    protected string $view = 'filament.presupuestos.resumen';

    /*
     * Sin pereza. Filament carga los widgets en una segunda peticion, y esto
     * es lo primero que se mira al abrir la pantalla: un hueco que se rellena
     * medio segundo despues hace leer la cifra dos veces para creersela. Son
     * seis presupuestos, no seiscientos.
     */
    protected static bool $isLazy = false;

    protected int | string | array $columnSpan = 'full';

    public function getResumen(): array
    {
        return Budget::resumenDelAno($this->ano());
    }

    /**
     * El año en curso, salvo que todavía no tenga presupuestos.
     *
     * En enero, antes de que la Universidad asigne lo del año nuevo, mirar
     * solo el año en curso enseñaría un panel en cero encima de una tabla con
     * seis presupuestos: parece una avería, y lleva a comprobar si se borro
     * algo. Mientras no haya nada vigente del año, se resume el último que sí
     * lo tiene, y la tarjeta dice de qué año habla.
     */
    public function ano(): int
    {
        $ahora = (int) now()->year;

        if (Budget::where('year', $ahora)->where('status', 'vigente')->exists()) {
            return $ahora;
        }

        return (int) (Budget::where('status', 'vigente')->max('year') ?? $ahora);
    }
}
