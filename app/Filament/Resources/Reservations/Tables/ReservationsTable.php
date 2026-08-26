<?php

namespace App\Filament\Resources\Reservations\Tables;

use App\Models\Asset;
use App\Models\Project;
use App\Models\Reservation;
use Filament\Actions\Action;
use App\Services\Projects\ProduccionService;
use App\Services\Projects\ProjectException;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Carbon;
use Filament\Notifications\Notification;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
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

                EditAction::make()->iconButton()->tooltip('Editar'),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
