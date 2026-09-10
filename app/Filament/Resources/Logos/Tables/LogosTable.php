<?php

namespace App\Filament\Resources\Logos\Tables;

use App\Models\Logo;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LogosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // El orden de la franja se decide arrastrando las filas.
            ->defaultSort('position')
            ->reorderable('position')
            ->columns([
                ImageColumn::make('imagen_path')
                    ->label('Logo')
                    ->disk('public')
                    ->height(40)
                    ->placeholder('—'),

                TextColumn::make('nombre')
                    ->label('De quién es')
                    ->weight('medium')
                    ->searchable(),

                TextColumn::make('url')
                    ->label('Lleva a')
                    ->limit(50)
                    ->placeholder('sin enlace')
                    ->color('gray'),

                IconColumn::make('is_active')
                    ->label('Se muestra')
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make()->iconButton()->tooltip('Editar'),
                DeleteAction::make()->iconButton()->tooltip('Borrar'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Todavía no hay logos')
            ->emptyStateDescription('La Universidad, la acreditación de calidad, la Fab Foundation, la Fab Academy: lo que respalda al laboratorio sale debajo del banner de la portada.');
    }
}
