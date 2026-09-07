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
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('starts_on', $direction)),

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

                /*
                 * El parametro se llama `$query` a proposito: Filament inyecta
                 * por nombre, y con otro nombre resuelve del contenedor un
                 * constructor de consultas sin modelo, que revienta al primer
                 * `where`. Produccion lo enseno con un 500 en esta lista.
                 */
                Filter::make('vigentes')
                    ->label('Solo vigentes')
                    ->default()
                    ->query(fn (Builder $query) => $query->where(fn (Builder $vigente) => $vigente
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
