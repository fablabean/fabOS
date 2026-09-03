<?php

namespace App\Filament\Resources\Reservations\Tables;

use App\Models\Asset;
use App\Models\Project;
use App\Models\Reservation;
use App\Services\Booking\EliminarReserva;
use Filament\Actions\Action;
use App\Services\Projects\ProduccionService;
use App\Services\Projects\ProjectException;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Carbon;
use Filament\Notifications\Notification;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder;

class ReservationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('starts_at', 'desc')
            ->columns([
                TextColumn::make('starts_at')
                    ->label('Cuándo')
                    ->dateTime('d/m/Y H:i', config('fabos.lab.timezone'))
                    ->description(fn (Reservation $record) => 'hasta ' .
                        $record->ends_at->timezone(config('fabos.lab.timezone'))->format('H:i'))
                    ->sortable(),

                TextColumn::make('recurso')
                    ->label('Recurso')
                    ->state(function (Reservation $record) {
                        // Polimórfico: puede ser un equipo o el tiempo de una persona.
                        $clase = class_basename($record->reservable_type);
                        $nombre = $record->reservable_type::find($record->reservable_id)?->name;

                        return $nombre ?? ($clase . ' #' . $record->reservable_id);
                    })
                    ->description(fn (Reservation $record) => $record->reservable_type === Asset::class
                        ? 'equipo'
                        : 'acompañamiento'),

                TextColumn::make('user.name')->label('Persona')->searchable(),

                TextColumn::make('supervisor.name')
                    ->label('Acompaña')
                    // El supervisor que exige el certifab, y además quienes se
                    // apuntaron a acompañar la actividad.
                    ->state(fn (Reservation $r) => collect([$r->supervisor?->name])
                        ->merge($r->companions->pluck('name'))
                        ->filter()->unique()->implode(', ') ?: null)
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn ($state) => Reservation::ESTADOS[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'confirmada', 'completada' => 'success',
                        'en_curso'                 => 'info',
                        'solicitada'               => 'warning',
                        default                    => 'danger',
                    })
                    ->sortable(),

                TextColumn::make('checked_in_at')
                    ->label('Llegada')
                    ->dateTime('H:i', config('fabos.lab.timezone'))
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('purpose')->label('Para qué')->limit(40)->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(Reservation::ESTADOS)->multiple(),

                Filter::make('proximas')
                    ->label('Solo próximas')
                    ->query(fn (Builder $q) => $q->where('ends_at', '>=', now())),

                Filter::make('solo_equipos')
                    ->label('Solo equipos')
                    ->query(fn (Builder $q) => $q->where('reservable_type', Asset::class))
                    ->default(),
            ])
            ->recordActions([
                // Producir lo que se acordó en la asesoría.
                //
                // Es el caso más común del laboratorio y el que no tenía sitio:
                // un estudiante llega con un archivo, el asesor mira que se
                // puede imprimir, y alguien tiene que apartar las seis horas de
                // máquina. La pieza es del estudiante —la reserva queda a su
                // nombre— pero la opera el asesor, así que no hace falta
                // certifab ni que la franja esté atendida.
                Action::make('producir')
                    ->label('Programar producción')
                    // Solo el icono: con cinco acciones por fila, el texto
                    // empuja fuera de pantalla lo que se vino a leer.
                    ->iconButton()
                    ->tooltip('Programar producción')
                    ->icon('heroicon-o-cube')
                    ->color('gray')
                    ->visible(fn (Reservation $record) => $record->advisory_asset_id !== null
                        && ! $record->is_production)
                    ->modalHeading('Programar producción')
                    ->modalDescription('La pieza queda a nombre de quien pidió la asesoría. Mientras dure, el equipo no aparecerá libre para nadie más.')
                    ->modalSubmitActionLabel('Programar')
                    ->fillForm(fn (Reservation $record) => [
                        'asset_id'  => $record->advisory_asset_id,
                        'starts_at' => $record->ends_at->timezone(config('fabos.lab.timezone')),
                    ])
                    ->schema([
                        Select::make('asset_id')
                            ->label('Con qué equipo')
                            ->required()
                            ->searchable()
                            ->options(fn () => Asset::where('status', '!=', 'baja')
                                ->orderBy('name')
                                ->pluck('name', 'id')),

                        DateTimePicker::make('starts_at')->label('Empieza')->seconds(false)->required(),

                        DateTimePicker::make('ends_at')
                            ->label('Termina')
                            ->seconds(false)
                            ->required()
                            ->helperText('Una pieza de seis horas ocupa seis horas, aunque nadie esté delante. Puede terminar de madrugada.'),

                        TextInput::make('purpose')
                            ->label('Qué se produce')
                            ->helperText('«Carcasa v3, 4 piezas». Dentro de un mes es lo único que explica por qué la máquina estuvo ocupada.'),

                        Select::make('project_id')
                            ->label('¿Es de algún proyecto?')
                            ->searchable()
                            ->placeholder('No, es la pieza de quien pidió la asesoría')
                            ->options(fn () => Project::where('status', 'activo')
                                ->orderByDesc('id')
                                ->get()
                                ->mapWithKeys(fn (Project $p) => [$p->id => $p->code . ' · ' . $p->name])),
                    ])
                    ->action(function (Reservation $record, array $data) {
                        $tz = config('fabos.lab.timezone');

                        try {
                            $produccion = app(ProduccionService::class)->programar(
                                Asset::findOrFail($data['asset_id']),
                                // De quien pidió la asesoría: la pieza es suya.
                                $record->user,
                                Carbon::parse($data['starts_at'], $tz),
                                Carbon::parse($data['ends_at'], $tz),
                                $data['project_id'] ? Project::find($data['project_id']) : null,
                                $data['purpose'] ?? null,
                                // Y la opera quien asesoró.
                                auth()->user(),
                            );
                        } catch (ProjectException $e) {
                            Notification::make()->danger()->title('No se pudo programar')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title('Producción programada')
                            ->body('El equipo queda ocupado del '
                                . $produccion->starts_at->timezone($tz)->format('d/m H:i')
                                . ' al ' . $produccion->ends_at->timezone($tz)->format('d/m H:i') . '.')
                            ->send();
                    }),

                // Cargar una sesión a un proyecto: es lo que hace que el
                // costeo del proyecto incluya ese tiempo de máquina y su
                // material. Se hace después de la sesión, que es cuando se
                // sabe a qué trabajo correspondió.
                Action::make('proyecto')
                    ->label('Cargar a proyecto')
                    ->iconButton()
                    ->tooltip('Cargar a un proyecto')
                    ->icon('heroicon-o-rocket-launch')
                    ->color('gray')
                    ->schema([
                        Select::make('project_id')
                            ->label('Proyecto')
                            ->options(fn () => Project::where('status', 'activo')
                                ->orderByDesc('id')
                                ->get()
                                ->mapWithKeys(fn (Project $p) => [$p->id => $p->code . ' · ' . $p->name]))
                            ->searchable()
                            ->placeholder('Ninguno — quitar del proyecto'),
                    ])
                    ->fillForm(fn (Reservation $record) => ['project_id' => $record->project_id])
                    ->action(function (Reservation $record, array $data) {
                        $record->update(['project_id' => $data['project_id'] ?: null]);

                        Notification::make()
                            ->title($data['project_id'] ? 'Cargada al proyecto' : 'Ya no se carga a ningún proyecto')
                            ->success()
                            ->send();
                    }),

                Action::make('aprobar')
                    ->label('Aprobar')
                    ->iconButton()
                    ->tooltip('Aprobar')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Al aprobarla, la reserva pasa a confirmada y bloquea el equipo.')
                    ->visible(fn (Reservation $record) => $record->status === 'solicitada')
                    ->action(fn (Reservation $record) => $record->update(['status' => 'confirmada'])),

                Action::make('rechazar')
                    ->label('Rechazar')
                    ->iconButton()
                    ->tooltip('Rechazar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Reservation $record) => $record->status === 'solicitada')
                    ->action(fn (Reservation $record) => $record->update([
                        'status' => 'rechazada',
                        'status_reason' => 'Rechazada desde el backoffice',
                    ])),

                /*
                 * Levantar una reserva que se cayo sola.
                 *
                 * El sistema marca «no se presento» cuando nadie valido la
                 * llegada, y esta bien: una maquina apartada que nadie usa es
                 * una maquina perdida. Pero hay reservas que no se validan
                 * porque no hace falta —la que se programa desde un proyecto se
                 * va a usar y punto—, y hasta ahora la unica salida era volver
                 * a crearla a mano, perdiendo quien la pidio y para que.
                 *
                 * Devolverla es un acto de alguien, con su motivo escrito: eso
                 * es lo que distingue corregir de tapar.
                 */
                Action::make('levantar')
                    ->label('Levantar la reserva')
                    ->iconButton()
                    ->tooltip('Levantar la reserva')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->visible(fn (Reservation $r) => in_array($r->status, ['no_show', 'cancelada', 'rechazada'], true))
                    ->modalHeading('Devolver la reserva')
                    ->modalDescription(fn (Reservation $r) => 'Está como «'
                        . (Reservation::ESTADOS[$r->status] ?? $r->status)
                        . '». Vuelve a quedar confirmada y el equipo, apartado.')
                    ->schema([
                        Textarea::make('motivo')
                            ->label('Por qué se levanta')
                            ->required()
                            ->placeholder('Se programó desde un proyecto: no hacía falta validar la llegada.')
                            ->helperText('Queda escrito en la reserva. Sin el motivo, dentro de un mes nadie sabe por qué se revivió.'),
                    ])
                    ->action(function (Reservation $record, array $data) {
                        $record->update([
                            'status'  => 'confirmada',
                            // Desde aqui se cuenta la tolerancia para validar la
                            // llegada: si no, nace fuera de plazo.
                            'reinstated_at' => now(),
                            'purpose' => trim(($record->purpose ? $record->purpose . ' · ' : '')
                                . 'Levantada por ' . auth()->user()->name . ': ' . $data['motivo']),
                        ]);

                        Notification::make()
                            ->success()
                            ->title('Reserva levantada')
                            ->body('Vuelve a estar confirmada. Se puede validar la llegada durante los próximos '
                                . config('fabos.checkin.tolerancia') . ' minutos.')
                            ->send();
                    }),

                EditAction::make()->iconButton()->tooltip('Editar'),

                /*
                 * Borrar desde la fila. Hasta ahora solo se podia entrando a
                 * editar, y para limpiar veinte reservas de prueba eso son
                 * cuarenta clics. Pasa por el mismo servicio que la seleccion
                 * multiple y que la ficha: devuelve lo comprometido y limpia
                 * los archivos. Quien puede lo decide la matriz.
                 */
                DeleteAction::make()
                    ->iconButton()
                    ->tooltip('Borrar')
                    ->modalHeading('Borrar la reserva')
                    ->modalDescription('Desaparece del historial. Si retenia FabCoins, se devuelven. Para dejar constancia de que no se uso, mejor cancelarla.')
                    ->action(function (Reservation $record, DeleteAction $action) {
                        app(EliminarReserva::class)($record, auth()->user());
                        $action->success();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->modalHeading('Borrar las reservas seleccionadas')
                        ->modalDescription('Desaparecen del historial. Lo que retuvieran en FabCoins se devuelve.')
                        ->action(function (Collection $records, DeleteBulkAction $action) {
                            $records->each(fn (Reservation $r) => app(EliminarReserva::class)($r, auth()->user()));
                            $action->success();
                        }),
                ]),
            ]);
    }
}
