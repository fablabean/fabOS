<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Models\Setting;
use App\Services\Money\BeneficioSemanal as Beneficio;
use App\Support\Settings;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * El beneficio semanal de FabCoins, desde el panel (§12).
 *
 * El tope, los dominios aliados y el interruptor. Lo que se ve antes de
 * pulsar: a quién le toca y cuánto se abonaría ahora mismo.
 */
class BeneficioSemanal extends Page
{
    use ControlaSuAcceso;

    protected string $view = 'filament.pages.beneficio-semanal';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static ?int $navigationSort = 5;

    public bool $activo = false;

    public string $tope = '8';

    public string $dominios = '';

    public string $equivalencias = '';

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Finanzas';
    }

    public static function getNavigationLabel(): string
    {
        return 'Beneficio semanal';
    }

    public function getTitle(): string
    {
        return 'Beneficio semanal de ' . config('fabos.currency.name') . 's';
    }

    public function mount(): void
    {
        $this->activo = Settings::beneficioActivo();
        $this->tope = number_format(Settings::beneficioSemanalMenor() / config('fabos.currency.minor_units'), 2, '.', '');
        $this->dominios = implode("\n", Settings::dominiosDelBeneficio());
        $this->equivalencias = Settings::equivalenciasDelBeneficio();
    }

    public function save(): void
    {
        $tope = (float) str_replace(',', '.', $this->tope);

        if ($tope <= 0) {
            Notification::make()->danger()->title('El tope tiene que ser mayor que cero')->send();

            return;
        }

        $dominios = collect(preg_split('/[\s,;]+/', $this->dominios) ?: [])
            ->map(fn ($d) => strtolower(trim(ltrim($d, '@'))))
            ->filter(fn ($d) => $d !== '' && str_contains($d, '.'))
            ->unique()
            ->values()
            ->all();

        if ($dominios === []) {
            Notification::make()->danger()->title('Hace falta al menos un dominio de correo')->send();

            return;
        }

        Setting::put(Settings::BENEFICIO_ACTIVO, $this->activo, 'finanzas');
        Setting::put(Settings::BENEFICIO_SEMANAL, (int) round($tope * config('fabos.currency.minor_units')), 'finanzas');
        Setting::put(Settings::BENEFICIO_DOMINIOS, $dominios, 'finanzas');
        Setting::put(Settings::BENEFICIO_EQUIVALENCIAS, trim($this->equivalencias), 'finanzas');

        $this->mount();

        Notification::make()
            ->title($this->activo ? 'Beneficio semanal activo' : 'Beneficio semanal apagado')
            ->body($this->activo
                ? 'Cada lunes se completa el saldo hasta ' . $this->tope . ' ' . config('fabos.currency.code') . ' a quien tenga correo de: ' . implode(', ', $dominios) . '.'
                : 'No se abona nada hasta que se vuelva a encender.')
            ->success()
            ->send();
    }

    /** Aplica el beneficio de esta semana ahora mismo, sin esperar al lunes. */
    public function aplicarAhora(): void
    {
        if (! Settings::beneficioActivo()) {
            Notification::make()->warning()->title('Está apagado')->body('Enciéndelo y guarda antes de aplicarlo.')->send();

            return;
        }

        $r = app(Beneficio::class)->aplicar();

        Notification::make()
            ->title('Beneficio de la semana ' . $r['semana'] . ' aplicado')
            ->body($r['abonos'] . ' abonos por ' . $this->enFabcoins($r['total']) . ' ' . config('fabos.currency.code')
                . '; ' . $r['completas'] . ' ya estaban completos.')
            ->success()
            ->send();
    }

    /** Lo que pasaría ahora mismo: a quién le toca y cuánto. */
    public function vistaPrevia(): array
    {
        return app(Beneficio::class)->aplicar(simular: true);
    }

    public function enFabcoins(int $menor): string
    {
        return number_format($menor / config('fabos.currency.minor_units'), 2, ',', '.');
    }
}
