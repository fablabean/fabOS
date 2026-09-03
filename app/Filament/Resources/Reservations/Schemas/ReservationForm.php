<?php

namespace App\Filament\Resources\Reservations\Schemas;

use App\Models\Reservation;
use App\Models\User;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * La ficha de una reserva ya hecha (§7).
 *
 * Crear pasa por otra pantalla, que pregunta lo mismo que el sitio público y
 * reserva por el mismo servicio. Aquí se corrige lo que ya existe: cambiar la
 * hora, anotar la llegada a mano, escribir por qué se canceló. Lo que no se
 * edita —qué recurso es, cuánto costó— se enseña, no se esconde: es lo que
 * explica el resto de la ficha.
 */
class ReservationForm
{
    public static function configure(Schema $schema): Schema
    {
        $unidades = config('fabos.currency.minor_units');
        $moneda = config('fabos.currency.code');

        return $schema
            ->components([
                Section::make('Qué y para quién')
                    ->columns(2)
                    ->schema([
                        // El recurso no se cambia desde aquí: cambiarlo es otra
                        // reserva, con otras reglas y otro costo.
                        Placeholder::make('recurso')
                            ->label('Recurso')
                            ->content(fn (?Reservation $record) => $record?->esAsesoria()
                                ? 'Asesoría' . ($record->sobreQue() ? ' sobre ' . $record->sobreQue() : '')
                                    . ' · atiende ' . ($record->reservable?->name ?? '—')
                                : ($record?->reservable?->name ?? '—')),

                        Placeholder::make('clase')
                            ->label('Clase')
                            ->content(fn (?Reservation $record) => match (true) {
                                $record === null => '—',
                                $record->esProduccion() => 'Producción (se programa desde el proyecto)',
                                $record->esAsesoria() => 'Asesoría',
                                default => Reservation::MODOS[$record->mode] ?? $record->mode,
                            }),

                        Select::make('user_id')
                            ->label('Para quién')
                            ->options(fn () => User::orderBy('name')->get()
                                ->mapWithKeys(fn (User $u) => [$u->id => $u->name . ' · ' . $u->email]))
                            ->searchable()
                            ->required(),

                        Select::make('supervisor_id')
                            ->label('Acompaña')
                            ->options(fn () => User::role(User::ROLES_BACKOFFICE)->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->placeholder('Nadie')
                            ->helperText('Quien acompaña una reserva con supervisión. Vacío si va por su cuenta.'),
                    ]),

                Section::make('Cuándo')
                    ->columns(2)
                    ->schema([
                        DateTimePicker::make('starts_at')->label('Empieza')->seconds(false)->required(),
                        DateTimePicker::make('ends_at')->label('Termina')->seconds(false)->required()->after('starts_at'),

                        DateTimePicker::make('checked_in_at')
                            ->label('Llegó')
                            ->seconds(false)
                            ->helperText('Se anota sola al escanear el QR. A mano, solo si el escáner falló.'),

                        DateTimePicker::make('checked_out_at')->label('Salió')->seconds(false),
                    ]),

                Section::make('Estado')
                    ->columns(2)
                    ->schema([
                        Select::make('status')
                            ->label('Estado')
                            ->options(Reservation::ESTADOS)
                            ->required()
                            ->helperText('Cancelar desde la lista devuelve lo comprometido; cambiarlo aquí a mano, no.'),

                        Select::make('mode')
                            ->label('Cómo se reservó')
                            ->options(Reservation::MODOS)
                            ->required(),

                        Textarea::make('purpose')->label('Para qué')->rows(2)->columnSpanFull(),

                        Textarea::make('status_reason')
                            ->label('Por qué está así')
                            ->rows(2)
                            ->columnSpanFull()
                            ->helperText('Lo que explica un rechazo, una cancelación o una devolución.'),
                    ]),

                Section::make('Costo')
                    ->description('Lo que costaría y lo que costó, en ' . config('fabos.currency.name') . '. Se calcula solo; aquí se corrige si hizo falta.')
                    ->columns(2)
                    ->collapsed()
                    ->schema([
                        // Se enseña en FabCoins y se guarda en unidades menores:
                        // el libro trabaja con enteros, y nadie piensa en
                        // centavos de FabCoin.
                        TextInput::make('estimated_cost_minor')
                            ->label('Estimado')
                            ->numeric()
                            ->step(0.01)
                            ->suffix($moneda)
                            ->formatStateUsing(fn ($state) => $state === null ? null : $state / $unidades)
                            ->dehydrateStateUsing(fn ($state) => $state === null ? 0 : (int) round((float) $state * $unidades)),

                        TextInput::make('actual_cost_minor')
                            ->label('Real')
                            ->numeric()
                            ->step(0.01)
                            ->suffix($moneda)
                            ->formatStateUsing(fn ($state) => $state === null ? null : $state / $unidades)
                            ->dehydrateStateUsing(fn ($state) => $state === null || $state === '' ? null : (int) round((float) $state * $unidades)),
                    ]),
            ]);
    }
}
