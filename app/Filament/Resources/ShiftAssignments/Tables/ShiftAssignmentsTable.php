<?php

namespace App\Filament\Resources\ShiftAssignments\Tables;

use App\Models\ShiftAssignment;
use App\Services\Staffing\OvertimeService;
use Filament\Actions\Action;
use App\Filament\Resources\ShiftAssignments\ShiftAssignmentResource;
use App\Services\Staffing\ReporteDeNomina;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ShiftAssignmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['project', 'reservation.reservable', 'user']))
            ->defaultSort('starts_at', 'desc')
            ->columns([
                TextColumn::make('user.name')->label('Persona')->searchable()->weight('medium'),

                TextColumn::make('starts_at')
                    ->label('Cuándo')
                    ->dateTime('d/m/Y H:i', config('fabos.lab.timezone'))
                    ->description(fn (ShiftAssignment $record) => 'hasta ' .
                        $record->ends_at->timezone(config('fabos.lab.timezone'))->format('H:i'))
                    ->sortable(),

                TextColumn::make('duracion')
                    ->label('Duración')
                    ->state(fn (ShiftAssignment $record) => round($record->minutos() / 60, 1) . ' h'),

                TextColumn::make('reason')
                    ->label('Motivo')
                    ->limit(30)
                    ->description(fn (ShiftAssignment $record) => collect([
                        $record->project ? $record->project->code . ' · ' . $record->project->name : null,
                        $record->reservation ? 'Reserva #' . $record->reservation->id . ' · ' . $record->reservation->nombreDelRecurso() : null,
                    ])->filter()->implode(' — ') ?: null),

                /*
                 * En qué va para nómina: programada, pendiente de reporte,
                 * reportada o compensada. Lo reporta cada quien desde «Mis
                 * horas extras»; aquí se ve.
                 */
                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->state(fn (ShiftAssignment $record) => ShiftAssignment::ESTADOS[$record->estado()])
                    ->color(fn (ShiftAssignment $record) => ShiftAssignment::COLORES[$record->estado()])
                    ->description(fn (ShiftAssignment $record) => match (true) {
                        $record->reporte_id !== null => 'Reporte #' . $record->reporte_id,
                        ! $record->counts_as_overtime && $record->compensa_el !== null => 'Se compensa el ' . $record->compensa_el->format('d/m'),
                        ! $record->counts_as_overtime && $record->user?->tieneContratoFlexible() => 'Contrato flexible · falta decir cuándo se compensa',
                        default => null,
                    }),

                // El acumulado de la semana es lo que evita que el mismo termine
                // cubriendo todos los sábados del semestre (§5).
                TextColumn::make('acumulado')
                    ->label('Extras esa semana')
                    ->state(function (ShiftAssignment $record) {
                        $min = app(OvertimeService::class)->minutosSemana($record->user, $record->starts_at);

                        return round($min / 60, 1) . ' h de ' . (config('fabos.overtime.max_semana_minutos') / 60);
                    })
                    ->badge()
                    ->color(function (ShiftAssignment $record) {
                        $usado = app(OvertimeService::class)->minutosSemana($record->user, $record->starts_at);
                        $tope  = config('fabos.overtime.max_semana_minutos');

                        return match (true) {
                            $usado >= $tope        => 'danger',
                            $usado >= $tope * 0.75 => 'warning',
                            default                => 'success',
                        };
                    }),

                TextColumn::make('accepted_at')
                    ->label('Aceptada')
                    ->dateTime('d/m/Y', config('fabos.lab.timezone'))
                    ->placeholder('pendiente'),

                TextColumn::make('conflict_note')
                    ->label('Conflicto reportado')
                    ->placeholder('—')
                    ->color('danger')
                    ->toggleable(),
            ])
            ->recordActions([
                Action::make('aceptar')
                    ->label('Marcar aceptada')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (ShiftAssignment $record) => $record->accepted_at === null)
                    ->action(fn (ShiftAssignment $record) => $record->update(['accepted_at' => now()])),

                EditAction::make(),
            ])
            ->toolbarActions([BulkActionGroup::make([
                /*
                 * Lo que ya se reportó a nómina antes de que cada quien
                 * sacara su formato aquí: queda como reportado, sin archivo,
                 * para que no aparezca pendiente ni se cobre otra vez.
                 */
                BulkAction::make('reportadasPorFuera')
                    ->label('Marcar como ya reportadas a nómina')
                    ->icon('heroicon-o-archive-box-arrow-down')
                    ->requiresConfirmation()
                    ->modalDescription('Para las horas que ya se reportaron a nómina por fuera de fabOS. Quedan como reportadas, sin archivo, y no salen en el próximo reporte de la persona. Solo cambian las que están pendientes.')
                    ->visible(fn () => ShiftAssignmentResource::canCreate())
                    ->action(function (\Illuminate\Support\Collection $records) {
                        $n = 0;
                        foreach ($records->groupBy('user_id') as $jornadas) {
                            $r = app(ReporteDeNomina::class)->marcarPorFuera($jornadas->first()->user, $jornadas->pluck('id')->all(), auth()->user());
                            $n += $r ? $r->jornadas()->count() : 0;
                        }
                        \Filament\Notifications\Notification::make()
                            ->title($n ? ($n === 1 ? 'Una jornada quedó reportada' : "{$n} jornadas quedaron reportadas") : 'Ninguna estaba pendiente de reporte')
                            ->success()->send();
                    })
                    ->deselectRecordsAfterCompletion(),

                // Las que ya fueron en un reporte no se borran (el modelo lo impide).
                DeleteBulkAction::make(),
            ])]);
    }
}
