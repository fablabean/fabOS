<?php

namespace App\Filament\Resources\ScheduleExceptions\Tables;

use App\Models\ScheduleException;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ScheduleExceptionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('starts_on', 'desc')
            ->columns([
                TextColumn::make('user.name')
                    ->label('Persona')
                    ->placeholder('Todo el laboratorio')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('kind')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ScheduleException::TIPOS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'bloqueo' => 'info',
                        'cierre', 'festivo' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('cuando')
                    ->label('Cuándo')
                    ->state(fn (ScheduleException $r) => $r->cuando())
                    ->sortable(query: fn (Builder $q, string $direction) => $q->orderBy('starts_on', $direction)),

                TextColumn::make('note')
                    ->label('Motivo')
                    ->placeholder('—')
                    ->searchable()
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('user_id')
                    ->label('Persona')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('kind')
                    ->label('Tipo')
                    ->options(ScheduleException::TIPOS),

                Filter::make('vigentes')
                    ->label('Solo vigentes')
                    ->default()
                    ->query(fn (Builder $q) => $q->where(fn (Builder $s) => $s
                        ->whereNull('ends_on')
                        ->orWhereDate('ends_on', '>=', now(config('fabos.lab.timezone'))->toDateString()))),
            ])
            ->recordActions([
                EditAction::make()->iconButton()->tooltip('Editar'),
                DeleteAction::make()->iconButton()->tooltip('Borrar'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
