<?php

namespace App\Filament\Resources\Reservations\Tables;

use App\Models\Asset;
use App\Models\Project;
use App\Models\Reservation;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
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
                // Cargar una sesión a un proyecto: es lo que hace que el
                // costeo del proyecto incluya ese tiempo de máquina y su
                // material. Se hace después de la sesión, que es cuando se
                // sabe a qué trabajo correspondió.
                Action::make('proyecto')
                    ->label('Cargar a proyecto')
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
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Al aprobarla, la reserva pasa a confirmada y bloquea el equipo.')
                    ->visible(fn (Reservation $record) => $record->status === 'solicitada')
                    ->action(fn (Reservation $record) => $record->update(['status' => 'confirmada'])),

                Action::make('rechazar')
                    ->label('Rechazar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Reservation $record) => $record->status === 'solicitada')
                    ->action(fn (Reservation $record) => $record->update([
                        'status' => 'rechazada',
                        'status_reason' => 'Rechazada desde el backoffice',
                    ])),

                EditAction::make(),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
