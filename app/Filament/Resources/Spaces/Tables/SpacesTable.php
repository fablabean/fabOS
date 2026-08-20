<?php

namespace App\Filament\Resources\Spaces\Tables;

use App\Models\Space;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SpacesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Espacio')->searchable()->weight('medium'),
                TextColumn::make('area.name')->label('Área')->placeholder('—')->searchable(),

                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => Space::TIPOS[$state] ?? $state)
                    ->color(fn ($state) => $state === 'virtual' ? 'info' : 'gray'),

                TextColumn::make('capacity')->label('Aforo')->placeholder('sin límite'),

                IconColumn::make('is_reservable')->label('Reservable')->boolean(),

                IconColumn::make('is_production_space')
                    ->label('De producción')
                    ->boolean()
                    ->tooltip('Donde se asesora, se monta y se produce'),

                TextColumn::make('location.name')->label('Ubicación')->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')->label('Tipo')->options(Space::TIPOS),
                SelectFilter::make('area')->label('Área')->relationship('area', 'name')->preload(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
