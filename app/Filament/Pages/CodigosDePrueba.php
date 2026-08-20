<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Support\CapturaDeCodigos;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Log;

/**
 * Los códigos de ingreso, a la vista, mientras duren las pruebas (§5).
 *
 * Hace falta porque el proveedor de correo, hasta que aprueba la cuenta, solo
 * entrega a direcciones del propio dominio: quien prueba desde su correo
 * institucional no recibe nada y no puede entrar.
 *
 * Lo que esta pantalla concede es poder entrar como cualquiera. Por eso la
 * captura caduca sola, tiene tope de una semana y deja rastro de quién la
 * encendió y quién la miró. El detalle de por qué está así, en
 * `App\Support\CapturaDeCodigos`.
 */
class CodigosDePrueba extends Page
{
    protected string $view = 'filament.pages.codigos-de-prueba';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEyeSlash;

    protected static ?int $navigationSort = 9;

    public int $horas = 24;

    /** Solo el superadmin, y se comprueba en el servidor. */
    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole(User::ROL_SUPERADMIN) ?? false;
    }

    /** No ensucia el menú cuando no se está usando. */
    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess() && CapturaDeCodigos::activa();
    }

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Configuración';
    }

    public static function getNavigationLabel(): string
    {
        return 'Códigos de prueba';
    }

    public function getTitle(): string
    {
        return 'Códigos de ingreso · pruebas';
    }

    public function activar(): void
    {
        $this->autorizar();

        $hasta = CapturaDeCodigos::activar($this->horas, auth()->user()->email);

        Notification::make()
            ->title('Captura activada')
            ->body('Se apagará sola el ' . $hasta->timezone(config('app.timezone'))->format('d/m/Y H:i') . '.')
            ->warning()
            ->send();
    }

    public function desactivar(): void
    {
        $this->autorizar();

        CapturaDeCodigos::desactivar(auth()->user()->email);

        Notification::make()->title('Captura apagada')->success()->send();
    }

    /**
     * @return array<int,array{email:string,codigo:string,expira:\Illuminate\Support\Carbon}>
     */
    public function getCodigosProperty(): array
    {
        $codigos = CapturaDeCodigos::listar();

        // Se registra el hecho de mirar, no los codigos: la bitacora no tiene
        // por que guardar en claro lo que esta pantalla enseña.
        if ($codigos !== []) {
            Log::warning('Códigos de ingreso consultados', [
                'quien'    => auth()->user()?->email,
                'cuantos'  => count($codigos),
            ]);
        }

        return $codigos;
    }

    public function getActivaProperty(): bool
    {
        return CapturaDeCodigos::activa();
    }

    public function getHastaProperty(): ?\Illuminate\Support\Carbon
    {
        return CapturaDeCodigos::hasta();
    }

    private function autorizar(): void
    {
        abort_unless(static::canAccess(), 403);
    }
}
