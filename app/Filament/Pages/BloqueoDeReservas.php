<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Models\Reservation;
use App\Support\BloqueoDeReservas as Bloqueo;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * El interruptor que cierra el laboratorio a las reservas (App\Support\BloqueoDeReservas).
 *
 * Se escribe el porqué, porque es lo que ve todo el que entra al sitio en el
 * aviso; y opcionalmente cuándo se reabre, para que se levante solo.
 */
class BloqueoDeReservas extends Page
{
    use ControlaSuAcceso;

    protected string $view = 'filament.pages.bloqueo-de-reservas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    protected static ?int $navigationSort = 7;

    /** @var array<string,mixed> */
    public array $datos = [];

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Operación';
    }

    public static function getNavigationLabel(): string
    {
        return 'Bloqueo de reservas';
    }

    public static function getNavigationBadge(): ?string
    {
        return Bloqueo::activo() ? 'Activo' : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function getTitle(): string
    {
        return 'Bloqueo de reservas';
    }

    public function mount(): void
    {
        $e = Bloqueo::estado();

        $this->form->fill([
            'motivo' => $e['motivo'],
            'hasta'  => Bloqueo::activo() ? Bloqueo::hasta() : null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('datos')
            ->components([
                Textarea::make('motivo')
                    ->label('Por qué se bloquea')
                    ->helperText('Es lo que lee todo el que entra al sitio, en el aviso. Escríbelo para quien reserva: «Por mantenimiento eléctrico del edificio».')
                    ->rows(3)
                    ->maxLength(400)
                    ->required(),
                DateTimePicker::make('hasta')
                    ->label('Se reabre el')
                    ->helperText('Opcional. Con fecha, se puede reservar desde ya para después de esa hora, y el bloqueo se levanta solo; sin ella, no se reserva nada hasta que alguien lo levante aquí.')
                    ->seconds(false)
                    ->minDate(now()),
            ]);
    }

    public function activar(): void
    {
        $estado = $this->form->getState();

        Bloqueo::activar(
            $estado['motivo'],
            filled($estado['hasta'] ?? null) ? Carbon::parse($estado['hasta']) : null,
            auth()->user()?->name,
        );

        Notification::make()
            ->title('Reservas bloqueadas')
            ->body('Nadie puede reservar hasta que se levante. El aviso ya sale en el sitio.')
            ->danger()
            ->send();
    }

    public function levantar(): void
    {
        Bloqueo::levantar();

        Notification::make()->title('Reservas abiertas de nuevo')->success()->send();
    }

    /** Las que siguen en pie mientras dure: para decidir si se cancelan. */
    public function reservasPorDelante(): int
    {
        $hasta = Bloqueo::hasta();

        return Reservation::query()
            ->whereIn('status', ['solicitada', 'confirmada'])
            ->where('ends_at', '>', now())
            ->when($hasta, fn ($q) => $q->where('starts_at', '<', $hasta))
            ->count();
    }
}
