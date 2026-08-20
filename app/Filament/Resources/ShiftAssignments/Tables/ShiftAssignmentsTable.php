<?php

namespace App\Filament\Resources\ShiftAssignments\Tables;

use App\Models\ShiftAssignment;
use App\Services\Staffing\OvertimeService;
use Filament\Actions\Action;
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

                TextColumn::make('reason')->label('Motivo')->limit(30),

                TextColumn::make('counts_as_overtime')
                    ->label('Cuenta como')
                    ->badge()
                    ->state(fn (ShiftAssignment $record) => $record->counts_as_overtime ? 'Hora extra' : 'Compensada')
                    ->color(fn ($state) => $state === 'Hora extra' ? 'warning' : 'gray'),

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
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
