<?php

namespace App\Filament\Resources\Locations\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LocationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Ubicación')->searchable()->weight('medium'),
                TextColumn::make('parent.name')->label('Dentro de')->placeholder('raíz')->searchable(),
                TextColumn::make('assets_count')->label('Equipos aquí')->counts('assets')->badge()->color('gray'),
                TextColumn::make('children_count')->label('Sub-ubicaciones')->counts('children')->badge()->color('gray'),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
