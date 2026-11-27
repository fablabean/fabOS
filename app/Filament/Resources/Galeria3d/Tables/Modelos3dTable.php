<?php

namespace App\Filament\Resources\Galeria3d\Tables;

use App\Models\Modelo3d;
use App\Support\Galeria3d;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class Modelos3dTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // El orden de la galería se decide arrastrando las filas.
            ->defaultSort('orden')
            ->reorderable('orden')
            ->emptyStateHeading('Todavía no has subido modelos')
            ->emptyStateDescription('Sube un STL, STEP, OBJ, FBX, 3MF o glTF: se convierte y se optimiza solo, y lo publicas cuando quieras.')
            ->emptyStateIcon('heroicon-o-cube')
            ->columns([
                ImageColumn::make('foto')
                    ->label('')
                    ->state(fn (Modelo3d $m) => $m->url('poster'))
                    ->width(64)
                    ->height(64)
                    ->extraImgAttributes(['style' => 'object-fit:contain']),

                TextColumn::make('nombre')
                    ->label('Modelo')
                    ->weight('medium')
                    ->description(fn (Modelo3d $m) => config('galeria3d.categorias.' . $m->categoria, $m->categoria) . ($m->formato_original ? ' · desde ' . strtoupper($m->formato_original) : ''))
                    ->searchable(['nombre', 'material', 'tecnica']),

                TextColumn::make('triangulos')
                    ->label('Triángulos')
                    ->state(fn (Modelo3d $m) => $m->estadisticas['optimizado']['triangulos'] ?? null)
                    ->numeric(thousandsSeparator: '.')
                    ->color('gray'),

                TextColumn::make('peso')
                    ->label('Peso web')
                    ->state(fn (Modelo3d $m) => Galeria3d::peso($m->estadisticas['optimizado']['bytes'] ?? null))
                    ->color('gray'),

                ToggleColumn::make('publicado')
                    ->label('Publicado')
                    ->afterStateUpdated(fn () => Galeria3d::olvidar()),

                TextColumn::make('updated_at')
                    ->label('Actualizado')
                    ->since()
                    ->color('gray'),
            ])
            ->recordActions([
                Action::make('ver')
                    ->label('Ver')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->iconButton()
                    ->tooltip('Ver en la galería')
                    ->visible(fn (Modelo3d $m) => $m->publicado)
                    ->url(fn (Modelo3d $m) => route('galeria.show', $m->slug), shouldOpenInNewTab: true),
                Action::make('qr')
                    ->label('QR')
                    ->icon('heroicon-o-qr-code')
                    ->color('gray')
                    ->iconButton()
                    ->tooltip('Descargar su código QR (abre la realidad aumentada)')
                    ->visible(fn (Modelo3d $m) => $m->publicado)
                    ->url(fn (Modelo3d $m) => route('galeria.qr', ['modelo' => $m->slug, 'descargar' => 1])),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
