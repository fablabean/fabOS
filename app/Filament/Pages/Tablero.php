<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Models\User;
use App\Services\Reports\DashboardService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * El tablero de indicadores (§17).
 *
 * Es la primera pantalla del backoffice a propósito: al entrar, lo que se
 * necesita saber no es cuántos activos hay sino **qué exige atención hoy**.
 * Por eso las alertas van arriba y cada una lleva a donde se resuelve; un
 * tablero que dice «hay tres problemas» sin decir dónde obliga a buscarlos, y
 * entonces nadie los busca.
 */
class Tablero extends Page
{
    use ControlaSuAcceso;

    protected string $view = 'filament.pages.tablero';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?int $navigationSort = -1;

    /** Sin grupo: es la entrada del panel, no una sección más. */
    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return null;
    }

    public static function getNavigationLabel(): string
    {
        return 'Tablero';
    }

    public function getTitle(): string
    {
        return 'Tablero del laboratorio';
    }

    public function getSubheading(): ?string
    {
        return 'Todo se calcula al abrir esta pantalla. Un tablero que dependiera de un '
            . 'proceso nocturno mostraría el laboratorio de ayer.';
    }


    /**
     * @return array<string,mixed>
     *
     * Quién mira decide qué se calcula, no solo qué se dibuja.
     *
     * El tablero lo abre casi todo el mundo —es la entrada del panel— y estaba
     * resumiendo aquí, en una pantalla abierta, datos que sus propias secciones
     * tienen cerradas: un practicante entraba y leía el presupuesto del
     * laboratorio entero.
     *
     * Esconderlo en la vista no habría bastado: la consulta seguiría corriendo
     * y el dato seguiría viajando al navegador, donde se lee sin más que abrir
     * el inspector. Lo que no se puede ver, no se calcula.
     */
    public function getViewData(): array
    {
        $servicio = app(DashboardService::class);
        $quien = auth()->user();

        return [
            // Ocupación y uso son operativos: quien atiende el laboratorio
            // necesita saber qué está en uso y qué se detuvo hoy.
            'ahora'     => $servicio->ahora(),
            'tendencia' => $servicio->tendencia(),

            // Estas dos preguntan a la matriz de accesos.
            'alertas'   => $servicio->alertas($quien),
            'finanzas'  => $servicio->finanzas($quien),
        ];
    }
}
