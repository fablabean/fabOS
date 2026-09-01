<?php

namespace App\Filament\Resources\Areas\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AreasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->reorderable('position')
            ->columns([
                // La foto con la que se presenta el area en Reservas. A la
                // vista para saber de un vistazo a cual le falta.
                \Filament\Tables\Columns\ImageColumn::make('photo_path')
                    ->label('Foto')
                    ->disk('public')
                    ->height(38)
                    ->defaultImageUrl(null),

                TextColumn::make('name')->label('Área')->searchable()->weight('medium'),
                TextColumn::make('slug')->label('Identificador')->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('risk_families_count')
                    ->label('Familias de riesgo')
                    ->counts('riskFamilies')
                    ->badge()->color('gray'),

                TextColumn::make('assets_count')
                    ->label('Equipos')
                    ->counts('assets')
                    ->badge()->color('gray'),

                TextColumn::make('description')->label('Descripción')->limit(60)->placeholder('—'),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
