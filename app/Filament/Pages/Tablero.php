<?php

namespace App\Filament\Pages;

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

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(User::ROLES_BACKOFFICE) ?? false;
    }

    /** @return array<string,mixed> */
    public function getViewData(): array
    {
        $servicio = app(DashboardService::class);

        return [
            'ahora'     => $servicio->ahora(),
            'alertas'   => $servicio->alertas(),
            'tendencia' => $servicio->tendencia(),
            'finanzas'  => $servicio->finanzas(),
        ];
    }
}
