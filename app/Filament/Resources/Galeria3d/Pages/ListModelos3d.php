<?php

namespace App\Filament\Resources\Galeria3d\Pages;

use App\Filament\Resources\Galeria3d\Galeria3dResource;
use App\Models\Setting;
use App\Support\Galeria3d;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListModelos3d extends ListRecords
{
    protected static string $resource = Galeria3dResource::class;

    public function getSubheading(): ?string
    {
        $ejemplos = count(config('galeria3d.modelos', []));

        return Galeria3d::conEjemplos()
            ? "En la galería también se ven los {$ejemplos} modelos de ejemplo. Puedes ocultarlos cuando tengas los tuyos."
            : 'Los modelos de ejemplo están ocultos: en la galería solo se ven los publicados aquí.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('ver')
                ->label('Ver la galería')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->url(fn () => route('galeria.index'), shouldOpenInNewTab: true),

            // Una tarjeta por figura con su QR, para ponerla junto a la pieza.
            Action::make('codigos-qr')
                ->label('Imprimir códigos QR')
                ->icon('heroicon-o-qr-code')
                ->color('gray')
                ->url(fn () => route('galeria.panel.qr'), shouldOpenInNewTab: true),

            Action::make('ejemplos')
                ->label(fn () => Galeria3d::conEjemplos() ? 'Ocultar los de ejemplo' : 'Mostrar los de ejemplo')
                ->icon(fn () => Galeria3d::conEjemplos() ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')
                ->color('gray')
                ->visible(fn () => Galeria3dResource::canCreate())
                ->requiresConfirmation()
                ->modalDescription(fn () => Galeria3d::conEjemplos()
                    ? 'Los modelos de ejemplo dejarán de verse en la galería pública. No se borran: puedes volver a mostrarlos.'
                    : 'Los modelos de ejemplo volverán a verse en la galería, después de los tuyos.')
                ->action(function () {
                    Setting::put(Galeria3d::AJUSTE_EJEMPLOS, ! Galeria3d::conEjemplos(), 'galeria3d');
                    Galeria3d::olvidar();
                    Notification::make()->title(Galeria3d::conEjemplos() ? 'Los de ejemplo se ven otra vez' : 'Los de ejemplo quedaron ocultos')->success()->send();
                }),

            Action::make('subir')
                ->label('Subir modelo')
                ->icon('heroicon-o-arrow-up-tray')
                ->visible(fn () => Galeria3dResource::canCreate())
                ->url(fn () => Galeria3dResource::getUrl('create')),
        ];
    }
}
