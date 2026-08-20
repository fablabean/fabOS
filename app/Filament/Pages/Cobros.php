<?php

namespace App\Filament\Pages;

use App\Models\LedgerAccount;
use App\Models\RateCard;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Support\Settings;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * El interruptor del dinero (§12).
 *
 * El cobro nace apagado a propósito: la tarifa ancla todavía no está decidida y
 * cobrar con números supuestos sería peor que no cobrar. Todo lo demás —tarifas,
 * cotizaciones, saldos— ya funciona; lo único que este interruptor decide es si
 * reservar mueve saldo de verdad.
 */
class Cobros extends Page
{
    protected string $view = 'filament.pages.cobros';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static ?int $navigationSort = 4;

    public bool $cobrosActivos = false;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole(User::ROL_SUPERADMIN) ?? false;
    }

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Finanzas';
    }

    public static function getNavigationLabel(): string
    {
        return 'Cobros';
    }

    public function getTitle(): string
    {
        return 'Cobros en ' . config('fabos.currency.name') . 's';
    }

    public function mount(): void
    {
        $this->cobrosActivos = Settings::cobrosActivos();
    }

    public function save(): void
    {
        Setting::put(Settings::COBROS_ACTIVOS, $this->cobrosActivos, 'finanzas');

        Notification::make()
            ->title($this->cobrosActivos ? 'Los cobros quedaron activos' : 'Los cobros quedaron apagados')
            ->body($this->cobrosActivos
                ? 'A partir de ahora reservar compromete saldo y cerrar liquida el consumo.'
                : 'Las reservas siguen funcionando, pero no mueven saldo.')
            ->success()
            ->send();
    }

    /** Lo que hay que decidir antes de encender esto. */
    public function pendientes(): array
    {
        return [
            'tarifas'   => RateCard::where('is_assumed', true)->count(),
            'total'     => RateCard::count(),
            'personas'  => LedgerAccount::where('kind', 'usuario')->count(),
            'emitido'   => -app(LedgerService::class)->cuentaDeSistema(LedgerAccount::EMISION)->saldoMenor(),
            'retenido'  => app(LedgerService::class)->cuentaDeSistema(LedgerAccount::GARANTIAS)->saldoMenor(),
            'causado'   => app(LedgerService::class)->cuentaDeSistema(LedgerAccount::INGRESO)->saldoMenor(),
            'cadena'    => app(LedgerService::class)->verificarCadena(),
        ];
    }

    public function enFabcoins(int $menor): string
    {
        return number_format($menor / config('fabos.currency.minor_units'), 2, ',', '.');
    }
}
