<?php

namespace App\Filament\Resources\WorkSchedules\Tables;

use App\Models\WorkSchedule;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WorkSchedulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultGroup('user.name')
            ->defaultSort('weekday')
            ->columns([
                TextColumn::make('weekday')
                    ->label('Día')
                    ->formatStateUsing(fn ($state) => WorkSchedule::DIAS[$state] ?? $state)
                    ->sortable(),

                TextColumn::make('horario')
                    ->label('Horario')
                    ->state(fn (WorkSchedule $record) => substr($record->starts_at, 0, 5)
                        . ' — ' . substr($record->ends_at, 0, 5)),

                TextColumn::make('break_minutes')
                    ->label('Descanso')
                    ->formatStateUsing(fn ($state) => $state . ' min')
                    ->tooltip('Sin este dato no se puede saber si la jornada roza el tope semanal'),

                TextColumn::make('efectivas')
                    ->label('Horas efectivas')
                    ->state(fn (WorkSchedule $record) => $record->horasEfectivas() . ' h')
                    ->badge()->color('gray'),

                TextColumn::make('effective_from')->label('Vigente desde')->date('d/m/Y'),
                TextColumn::make('effective_until')->label('Hasta')->date('d/m/Y')->placeholder('sin fin'),
            ])
            ->filters([
                SelectFilter::make('user_id')->label('Persona')->relationship('user', 'name')->preload(),
                SelectFilter::make('weekday')->label('Día')->options(WorkSchedule::DIAS),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
