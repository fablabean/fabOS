<?php

namespace App\Filament\Resources\Banners\Pages;

use App\Filament\Resources\Banners\BannerResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBanners extends ListRecords
{
    protected static string $resource = BannerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Añadir una lámina'),

            // Lo que se edita aqui se ve alli, y no en una vista previa que
            // miente: la portada es la vista previa.
            Action::make('ver')
                ->label('Ver la portada')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->url(fn () => route('publico.home'), shouldOpenInNewTab: true),
        ];
    }
}
