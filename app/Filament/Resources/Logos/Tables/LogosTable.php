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
                    /*
                     * Este NO se recorta.
                     *
                     * Un logo apaisado cortado en cuadrado deja de ser un
                     * logo: se come el nombre de quien firma. La columna se
                     * alinea igual dandole a todas las celdas la misma caja y
                     * metiendo el logo DENTRO -`contain`- en vez de llenarla.
                     */
                    ->width(96)
                    ->height(40)
                    ->extraImgAttributes(['style' => 'object-fit:contain'])
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
