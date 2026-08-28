<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Administracion de los metodos de ingreso (§5).
 *
 * El ingreso por carne se abre para las pruebas y se cierra desde aqui cuando
 * el correo institucional este habilitado, sin desplegar codigo.
 */
class Accesos extends Page
{
    use ControlaSuAcceso;

    protected string $view = 'filament.pages.accesos';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    public bool $carnetLogin = false;
    public bool $otpLogin = true;


    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Configuración';
    }

    public static function getNavigationLabel(): string
    {
        return 'Accesos';
    }

    public function getTitle(): string
    {
        return 'Métodos de acceso';
    }

    public function mount(): void
    {
        $this->carnetLogin      = Settings::carnetLoginEnabled();
        $this->otpLogin         = Settings::otpLoginEnabled();
    }

    public function save(): void
    {
        // Nunca dejar el sistema sin ninguna puerta: si se apagan las dos,
        // nadie podria entrar despues ni siquiera a volver a encenderlas.
        if (! $this->otpLogin && ! $this->carnetLogin) {
            $this->otpLogin = true;

            Notification::make()
                ->title('No se pueden apagar los dos métodos')
                ->body('El ingreso por código quedó activo: si no, nadie podría volver a entrar.')
                ->warning()
                ->send();
        }


        Setting::put(Settings::CARNET_LOGIN, $this->carnetLogin, 'auth');
        Setting::put(Settings::OTP_LOGIN, $this->otpLogin, 'auth');

        Notification::make()->title('Accesos actualizados')->success()->send();
    }
}
