<?php

namespace App\Filament\Resources\Paginas\Tables;

use App\Models\Pagina;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class PaginasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                ImageColumn::make('portada_path')
                    ->label('Portada')
                    ->square()
                    ->height(38)
                    ->disk('public')
                    ->extraImgAttributes(['style' => 'border-radius:.35rem;object-fit:cover'])
                    ->placeholder('—'),

                TextColumn::make('titulo')
                    ->label('Título')
                    ->weight('medium')
                    ->description(fn (Pagina $p) => '/p/'.$p->slug)
                    ->searchable(['titulo', 'slug'])
                    ->limit(60),

                // De donde salio. Enseña de un vistazo cuales son paginas de
                // proyecto y cuales se escribieron a mano, que es la pregunta
                // que se hace quien vuelve a esta lista dos meses despues.
                TextColumn::make('project.name')
                    ->label('Proyecto')
                    ->placeholder('Escrita a mano')
                    ->color('gray')
                    ->limit(30)
                    ->toggleable(),

                TextColumn::make('bloques')
                    ->label('Bloques')
                    ->badge()
                    ->color('gray')
                    ->state(fn (Pagina $p) => count($p->bloquesVisibles()))
                    ->alignCenter(),

                /*
                 * Publicada no es lo mismo que visible: una pagina publicada
                 * cuya fecha ya paso no se ve. Enseñar solo el interruptor
                 * haria buscar durante un rato por que devuelve «no existe».
                 */
                IconColumn::make('is_active')
                    ->label('Se ve ahora')
                    ->boolean()
                    ->state(fn (Pagina $p) => $p->estaVisible())
                    ->tooltip(fn (Pagina $p) => match (true) {
                        ! $p->is_active => 'Sin publicar',
                        (bool) $p->starts_at?->isFuture() => 'Todavía no empieza',
                        (bool) $p->ends_at?->isPast() => 'Ya terminó',
                        default => 'En el sitio',
                    }),

                TextColumn::make('updated_at')
                    ->label('Última edición')
                    ->dateTime('d M Y H:i')
                    ->timezone(config('fabos.lab.timezone'))
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Publicación')
                    ->placeholder('Todas')
                    ->trueLabel('Publicadas')
                    ->falseLabel('Sin publicar'),
            ])
            ->recordActions([
                EditAction::make()->iconButton()->tooltip('Editar'),

                /*
                 * Abrir la pagina, este publicada o no.
                 *
                 * Quien la edita entra con sesion y con permiso sobre la
                 * seccion, y para esa persona la pagina se ve aunque siga
                 * apagada: revisar como quedo antes de encenderla es el paso
                 * que hace que se encienda con algo mirado. Una vista previa
                 * aparte seria otra plantilla que puede mentir.
                 */
                Action::make('ver')
                    ->label('Ver la página')
                    ->iconButton()
                    ->tooltip(fn (Pagina $p) => $p->estaVisible() ? 'Ver la página' : 'Ver el borrador')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (Pagina $p) => $p->enlace())
                    ->openUrlInNewTab(),

                // Casi toda pagina nueva es «la anterior, con otro contenido».
                // Duplicar ahorra volver a montar los bloques, que es donde se
                // va el rato.
                ReplicateAction::make()
                    ->label('Duplicar')
                    ->iconButton()
                    ->tooltip('Duplicar')
                    ->excludeAttributes(['slug'])
                    ->beforeReplicaSaved(function (Pagina $replica): void {
                        $replica->titulo = $replica->titulo.' (copia)';
                        $replica->slug = Pagina::slugLibre($replica->titulo);
                        $replica->is_active = false;
                    }),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->emptyStateHeading('Todavía no hay páginas')
            ->emptyStateDescription('Una página sirve para contar algo que no cabe en el banner: un proyecto, una convocatoria, la página a la que lleva un botón de la portada. Las de proyecto se crean desde la lista de proyectos, ya rellenas.');
    }
}
