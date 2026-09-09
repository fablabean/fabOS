<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Models\Setting;
use App\Services\Projects\AcuerdoDeServicio as Acuerdo;
use App\Support\Settings;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * La base del acuerdo de servicio (§11).
 *
 * Las cláusulas que el sistema pone en cada acuerdo, escritas una vez. Lo que
 * cambia de un proyecto a otro va entre llaves y se rellena solo; lo demás es
 * el texto de la casa, y se corrige aquí cuando el laboratorio cambie de
 * reglas, no en cada contrato.
 */
class AcuerdoDeServicio extends Page
{
    use ControlaSuAcceso;

    protected string $view = 'filament.pages.acuerdo-de-servicio';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 30;

    public string $clausulas = '';

    public string $formaDePago = '';

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Proyectos';
    }

    public static function getNavigationLabel(): string
    {
        return 'Acuerdo de servicio';
    }

    public function getTitle(): string
    {
        return 'La base del acuerdo de servicio';
    }

    public function mount(): void
    {
        $this->clausulas = Acuerdo::clausulasBase();
        $this->formaDePago = Acuerdo::formaDePagoBase();
    }

    public function save(): void
    {
        Setting::put(Settings::ACUERDO_CLAUSULAS, trim($this->clausulas), 'proyectos');
        Setting::put(Settings::ACUERDO_FORMA_PAGO, trim($this->formaDePago), 'proyectos');

        Notification::make()
            ->title('Base guardada')
            ->body('Los próximos acuerdos salen con este texto. Los ya generados no cambian.')
            ->success()
            ->send();
    }

    /** Vuelve al texto que trae el sistema. */
    public function restablecer(): void
    {
        $this->clausulas = Acuerdo::CLAUSULAS_BASE;
        $this->formaDePago = Acuerdo::FORMA_PAGO_BASE;

        Setting::put(Settings::ACUERDO_CLAUSULAS, '', 'proyectos');
        Setting::put(Settings::ACUERDO_FORMA_PAGO, '', 'proyectos');

        Notification::make()->title('Base restablecida')->success()->send();
    }

    /** @return array<string,string> */
    public function variables(): array
    {
        return Acuerdo::VARIABLES;
    }
}
