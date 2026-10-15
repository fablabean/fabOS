<?php

namespace App\Filament\Resources\Anuncios\Tables;

use App\Models\Anuncio;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AnunciosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('programaciones'))
            ->defaultSort('position')
            ->reorderable('position')
            // Mientras haya un audio en camino, la lista se refresca sola: así
            // se ve pasar de «en cola» a «listo» sin recargar.
            ->poll(fn () => Anuncio::query()->whereIn('audio_estado', ['pendiente', 'generando'])->exists() ? '10s' : null)
            ->columns([
                TextColumn::make('titulo')
                    ->label('Anuncio')
                    ->weight('medium')
                    ->description(fn (Anuncio $a) => \Illuminate\Support\Str::limit($a->texto, 90))
                    ->searchable(['titulo', 'texto']),

                TextColumn::make('voz')
                    ->label('Voz')
                    ->formatStateUsing(fn (string $state) => ucfirst($state))
                    ->color('gray'),

                TextColumn::make('audio_estado')
                    ->label('Audio')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Anuncio::ESTADOS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'listo' => 'success',
                        'fallo' => 'danger',
                        default => 'warning',
                    })
                    ->tooltip(fn (Anuncio $a) => $a->audio_estado === 'fallo' ? $a->audio_error : null),

                TextColumn::make('horario')
                    ->label('Horario fijo')
                    ->state(fn (Anuncio $a) => $a->programaciones->map->resumen()->all())
                    ->listWithLineBreaks()
                    ->placeholder('—')
                    ->color('gray'),

                IconColumn::make('is_active')
                    ->label('En la portada')
                    ->boolean(),

                TextColumn::make('ends_at')
                    ->label('Hasta')
                    ->dateTime('d/m/Y H:i', config('fabos.lab.timezone'))
                    ->placeholder('sin fecha')
                    ->color('gray'),
            ])
            ->recordActions([
                // Suena en el navegador, sin ir al servidor: el audio ya está
                // guardado. Usa el reproductor compartido del panel.
                Action::make('escuchar')
                    ->label('Escuchar')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->iconButton()
                    ->tooltip('Escuchar / detener')
                    ->visible(fn (Anuncio $a) => $a->tieneAudioAlDia())
                    ->extraAttributes(fn (Anuncio $a) => ['data-anuncio-audio' => $a->audioUrl()])
                    ->alpineClickHandler(fn (Anuncio $a) => 'window.fabosAnuncios.tocar(' . \Illuminate\Support\Js::from($a->audioUrl())
                        . ', ' . \Illuminate\Support\Js::from($a->sonidoPrevioUrl()) . ')'),
                Action::make('favorito')
                    ->label(fn (Anuncio $a) => $a->favorito ? 'Quitar de favoritos' : 'Añadir a favoritos')
                    ->icon(fn (Anuncio $a) => $a->favorito ? 'heroicon-s-star' : 'heroicon-o-star')
                    ->color(fn (Anuncio $a) => $a->favorito ? 'warning' : 'gray')
                    ->iconButton()
                    ->tooltip(fn (Anuncio $a) => $a->favorito ? 'Quitar de la barra de arriba' : 'Poner en la barra de arriba')
                    ->action(function (Anuncio $a, $livewire) {
                        $a->update(['favorito' => ! $a->favorito]);
                        // La barra de arriba se vuelve a pintar sin recargar.
                        $livewire->dispatch('anuncios-favoritos');
                    }),
                Action::make('volverAGenerar')
                    ->label('Volver a generar')
                    ->icon('heroicon-o-arrow-path')
                    ->iconButton()
                    ->tooltip('Volver a generar el audio')
                    ->visible(fn (Anuncio $a) => $a->audio_estado === 'fallo')
                    ->action(function (Anuncio $a) {
                        $a->pedirAudio();

                        Notification::make()->success()
                            ->title('En cola otra vez')
                            ->body('El audio aparece en cuanto el servidor de voz lo genere.')
                            ->send();
                    }),
                EditAction::make()->iconButton()->tooltip('Editar'),
                DeleteAction::make()->iconButton()->tooltip('Borrar'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Todavía no hay anuncios')
            ->emptyStateDescription('Un horario especial, una feria, una máquina en mantenimiento: se escribe aquí, el servidor del FabLab lo convierte en voz y se oye en la portada.');
    }
}
