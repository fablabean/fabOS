<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Models\ProjectChange;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * El historial del proyecto: quién lo tuvo a cargo y por qué etapas y
 * estados pasó, con quién hizo cada cambio y cuándo. Solo se lee: lo anota
 * el propio proyecto al guardarse.
 */
class ChangesRelationManager extends RelationManager
{
    protected static string $relationship = 'changes';

    protected static ?string $title = 'Historial';

    protected static ?string $modelLabel = 'cambio';

    protected static ?string $pluralModelLabel = 'cambios';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Cada cambio de responsable, etapa o estado, con quién lo hizo. Se anota desde el 9 de noviembre de 2026: lo anterior no quedó guardado.')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Cuándo')
                    ->dateTime('d/m/Y H:i', config('fabos.lab.timezone'))
                    ->sortable(),
                TextColumn::make('field')
                    ->label('Qué cambió')
                    ->formatStateUsing(fn ($state) => ProjectChange::CAMPOS[$state] ?? $state)
                    ->badge()
                    ->color(fn ($state) => $state === 'responsable' ? 'warning' : 'gray'),
                TextColumn::make('from_value')
                    ->label('Antes')
                    ->placeholder('—'),
                TextColumn::make('to_value')
                    ->label('Después')
                    ->placeholder('Sin responsable')
                    ->weight('bold'),
                TextColumn::make('user_name')
                    ->label('Quién')
                    ->placeholder('El sistema'),
            ])
            ->filters([
                SelectFilter::make('field')->label('Qué cambió')->options(ProjectChange::CAMPOS),
            ])
            ->emptyStateHeading('Sin cambios anotados')
            ->emptyStateDescription('Los cambios de responsable, etapa o estado aparecen aquí desde el 9 de noviembre de 2026.');
    }
}
