<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Money\ChargeService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * Emitir la dotación del periodo (§12).
 *
 * Estaba programada el día 1 de cada mes y funcionaba: el 1 de septiembre a la
 * una de la mañana aparecieron 3.100 FabCoins repartidos entre seis personas,
 * sin que nadie lo hubiera decidido ese mes y sin nombre en el asiento.
 *
 * Emitir moneda es un acto del laboratorio, no una consecuencia del calendario.
 * Aquí se ve **a quién y cuánto antes de pulsar**, y lo emitido queda firmado.
 */
class Dotacion extends Page
{
    use ControlaSuAcceso;

    protected string $view = 'filament.pages.dotacion';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 5;

    /** El periodo que se va a emitir, en AAAA-MM. */
    public string $periodo = '';

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Finanzas';
    }

    public static function getNavigationLabel(): string
    {
        return 'Dotación';
    }

    public function getTitle(): string
    {
        return 'Dotación del periodo';
    }

    public function mount(): void
    {
        $this->periodo = Carbon::now(config('fabos.lab.timezone'))->format('Y-m');
    }

    /**
     * Quién recibiría y cuánto, con lo ya emitido marcado.
     *
     * Se calcula antes de emitir para poder mirarlo: una lista de nombres y
     * cifras se revisa; un botón que dice «emitir» no.
     */
    public function aQuien(): array
    {
        $yaEmitido = $this->yaEmitido();

        return User::query()
            ->where('status', 'activo')
            ->whereHas('category', fn ($q) => $q->where('allowance_minor', '>', 0))
            ->with('category')
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => [
                'persona'   => $u,
                'categoria' => $u->category?->name,
                'importe'   => (int) $u->category->allowance_minor,
                'ya'        => in_array($u->id, $yaEmitido, true),
            ])
            ->all();
    }

    /** Los que ya tienen la dotación de este periodo: repetir no abona dos veces. */
    public function yaEmitido(): array
    {
        return LedgerEntry::query()
            ->where('direction', 'C')
            ->whereHas('transaction', fn ($q) => $q
                ->where('kind', 'dotacion')
                ->where('idempotency_key', 'like', '%:' . $this->periodo))
            ->with('account')
            ->get()
            ->map(fn (LedgerEntry $e) => $e->account?->owner_id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** Lo que se abonaría ahora: sin contar lo ya emitido. */
    public function pendiente(): int
    {
        return collect($this->aQuien())->reject(fn (array $f) => $f['ya'])->sum('importe');
    }

    /** Las categorías y su dotación, que es de donde sale todo esto. */
    public function categorias()
    {
        return UserCategory::where('allowance_minor', '>', 0)->orderBy('name')->get();
    }

    public function emitir(): void
    {
        $cobros = app(ChargeService::class);
        $cuantos = 0;
        $total = 0;

        foreach ($this->aQuien() as $fila) {
            if ($fila['ya']) {
                continue;
            }

            // Firmada: quien emite queda en el asiento.
            $cobros->dotar(
                $fila['persona'],
                $fila['importe'],
                $this->periodo,
                null,
                auth()->user(),
            );

            $cuantos++;
            $total += $fila['importe'];
        }

        if ($cuantos === 0) {
            Notification::make()
                ->title('No había nada que emitir')
                ->body('Todo el mundo ya tiene su dotación de ' . $this->periodo . '.')
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title('Dotación emitida')
            ->body($cuantos . ' personas · ' . number_format($total / config('fabos.currency.minor_units'), 2, ',', '.')
                . ' ' . config('fabos.currency.code') . '. Queda a tu nombre en el libro.')
            ->send();
    }
}
