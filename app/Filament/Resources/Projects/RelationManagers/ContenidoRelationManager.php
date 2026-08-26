<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Models\Contenido;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * El material grabado durante el proyecto.
 *
 * No se sube desde aquí: se sube desde el teléfono, delante de la máquina, que
 * es cuando existe. Aquí se mira, que es lo que hace falta al escribir el
 * informe de cierre o al buscar una foto para enseñar lo que se hizo.
 */
class ContenidoRelationManager extends RelationManager
{
    protected static string $relationship = 'contenido';

    protected static ?string $title = 'Material';

    protected static ?string $modelLabel = 'pieza de material';

    protected static ?string $pluralModelLabel = 'material';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Todavía no hay material')
            ->emptyStateDescription('Se graba con el teléfono desde /contenido y queda aquí solo.')
            ->columns([
                ImageColumn::make('miniatura')
                    ->label('')
                    ->height(56)
                    ->extraImgAttributes(['style' => 'border-radius:.35rem;object-fit:cover'])
                    ->getStateUsing(fn (Contenido $r) => $r->esVideo() ? null : $r->enlace()),

                TextColumn::make('titulo')
                    ->label('Qué es')
                    ->wrap()
                    ->state(fn (Contenido $r) => $r->comoSeLlama())
                    ->description(fn (Contenido $r) => $r->description),

                TextColumn::make('kind')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Contenido::TIPOS[$state] ?? $state),

                TextColumn::make('user.name')->label('Quién lo grabó'),

                TextColumn::make('created_at')
                    ->label('Cuándo')
                    ->dateTime('d/m/Y H:i')
                    ->timezone(config('fabos.lab.timezone'))
                    ->sortable(),
            ])
            ->headerActions([])
            ->recordActions([
                Action::make('abrir')
                    ->label('Abrir')
                    ->iconButton()
                    ->tooltip('Abrir el archivo')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (Contenido $r) => $r->enlace())
                    ->openUrlInNewTab(),
            ])
            ->toolbarActions([]);
    }
}
