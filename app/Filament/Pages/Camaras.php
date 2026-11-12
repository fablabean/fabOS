<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Support\Camaras as LasCamaras;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Lo que ven las cámaras del laboratorio, casi en vivo.
 *
 * Es vigilancia: por eso nace cerrada. La ve el superadmin, y a cualquier otro
 * rol se le abre a propósito en Configuración → Roles y accesos.
 */
class Camaras extends Page
{
    use ControlaSuAcceso;

    protected string $view = 'filament.pages.camaras';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedVideoCamera;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Operación';
    }

    public static function getNavigationLabel(): string
    {
        return 'Cámaras';
    }

    public function getTitle(): string
    {
        return 'Cámaras';
    }

    /** @return list<array{numero:int,nombre:string,foto:string}> */
    public function camaras(): array
    {
        return array_map(fn (array $c) => [
            'numero' => $c['numero'],
            'nombre' => $c['nombre'],
            'foto' => route('panel.camara.foto', $c['numero']),
        ], LasCamaras::todas());
    }
}
