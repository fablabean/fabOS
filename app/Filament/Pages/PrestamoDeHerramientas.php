<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Models\Asset;
use App\Models\Setting;
use App\Support\Settings;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Cuántas herramientas sueltas caben en una reserva (§7).
 *
 * Es un número que decide la coordinación mirando el taller, no el código:
 * cinco hoy, y el día que alguien pida cuatro taladros «por si acaso» se baja
 * desde aquí sin desplegar nada.
 */
class PrestamoDeHerramientas extends Page
{
    use ControlaSuAcceso;

    protected string $view = 'filament.pages.prestamo-de-herramientas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?int $navigationSort = 6;

    public int $maximo = 5;

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Operación';
    }

    public static function getNavigationLabel(): string
    {
        return 'Préstamo de herramientas';
    }

    public function getTitle(): string
    {
        return 'Préstamo de herramientas';
    }

    public function mount(): void
    {
        $this->maximo = Settings::maxHerramientasPorReserva();
    }

    public function save(): void
    {
        $this->validate(['maximo' => ['required', 'integer', 'min:1', 'max:50']]);

        Setting::put(Settings::HERRAMIENTAS_POR_RESERVA, $this->maximo, 'reservas');

        Notification::make()
            ->title('Guardado')
            ->body('Desde ahora se pueden pedir hasta ' . $this->maximo . ' herramientas en una reserva.')
            ->success()
            ->send();
    }

    /** Lo que se presta hoy: para ver el tope con el taller delante. */
    public function getHerramientasProperty(): array
    {
        $todas = Asset::where('kind', 'herramienta')->count();
        $prestables = Asset::where('kind', 'herramienta')->where('is_reservable', true)->count();
        $portatiles = Asset::where('kind', 'herramienta')->where('is_reservable', true)->where('puede_salir', true)->count();

        return ['todas' => $todas, 'prestables' => $prestables, 'portatiles' => $portatiles];
    }
}
