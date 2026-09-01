<?php

namespace App\Filament\Resources\Banners\Tables;

use App\Models\Banner;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BannersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Se arrastra: el orden del banner es una decision visual y se toma
            // mirando la lista, no escribiendo numeros en un campo.
            ->defaultSort('position')
            ->reorderable('position')
            ->columns([
                ImageColumn::make('fondo_path')
                    ->label('Fondo')
                    ->height(38)
                    /*
                     * La URL entera, no la ruta.
                     *
                     * Conviven dos origenes: lo que se sube vive en el disco
                     * publico y las ilustraciones que trae fabOS estan en
                     * `public/img`. Con un solo disco declarado, las segundas
                     * salian rotas aqui aunque en la portada se vieran bien.
                     *
                     * Y del video se enseña su cartel: una celda no reproduce
                     * nada, pero el cartel es justo lo que representa esa
                     * lamina.
                     */
                    ->state(fn (Banner $b) => $b->esVideo() ? $b->posterUrl() : $b->fondoUrl())
                    ->placeholder('—'),

                TextColumn::make('titulo')
                    ->label('Título')
                    ->weight('medium')
                    ->description(fn (Banner $b) => $b->rotulo)
                    ->searchable()
                    ->limit(60),

                TextColumn::make('fondo_tipo')
                    ->label('Fondo')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state) => Banner::FONDOS[$state] ?? $state),

                TextColumn::make('efecto')
                    ->label('Efecto')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state) => Banner::EFECTOS[$state] ?? $state)
                    ->toggleable(isToggledHiddenByDefault: true),

                /*
                 * Encendida no es lo mismo que visible: una lamina encendida
                 * cuya fecha ya paso no sale en la portada. Enseñar solo el
                 * interruptor haria buscar durante un rato por que no se ve.
                 */
                IconColumn::make('is_active')
                    ->label('Se ve ahora')
                    ->boolean()
                    ->state(fn (Banner $b) => $b->is_active
                        && (! $b->starts_at || $b->starts_at->isPast())
                        && (! $b->ends_at || $b->ends_at->isFuture()))
                    ->tooltip(fn (Banner $b) => match (true) {
                        ! $b->is_active => 'Apagada',
                        (bool) $b->starts_at?->isFuture() => 'Todavía no empieza',
                        (bool) $b->ends_at?->isPast() => 'Ya terminó',
                        default => 'En la portada',
                    }),

                TextColumn::make('ends_at')
                    ->label('Hasta')
                    ->dateTime('d M Y H:i')
                    ->timezone(config('fabos.lab.timezone'))
                    ->placeholder('Sin fecha')
                    ->color(fn (Banner $b) => $b->ends_at?->isPast() ? 'danger' : null),
            ])
            ->recordActions([
                EditAction::make(),

                // Casi todas las laminas nuevas son «la anterior, con otro
                // texto». Duplicar ahorra volver a subir la foto y a decidir
                // el velo, que es donde se va el rato.
                ReplicateAction::make()
                    ->label('Duplicar')
                    ->excludeAttributes(['position'])
                    ->beforeReplicaSaved(function (Banner $replica): void {
                        $replica->titulo = $replica->titulo . ' (copia)';
                        $replica->is_active = false;
                        $replica->position = (int) Banner::max('position') + 1;
                    }),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->emptyStateHeading('La portada está usando el banner de fábrica')
            ->emptyStateDescription('Mientras no haya ninguna lámina aquí, se enseñan las que trae fabOS. En cuanto añadas una, manda esta lista.');
    }
}
