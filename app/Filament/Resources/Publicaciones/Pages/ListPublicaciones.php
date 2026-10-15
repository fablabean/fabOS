<?php

namespace App\Filament\Resources\Publicaciones\Pages;

use App\Filament\Resources\Publicaciones\PublicacionResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/** La parrilla como lista: para buscar, filtrar y ver lo que va tarde. */
class ListPublicaciones extends ListRecords
{
    protected static string $resource = PublicacionResource::class;

    public function getTitle(): string
    {
        return 'Publicaciones';
    }

    public function getBreadcrumb(): string
    {
        return 'Lista';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('parrilla')
                ->label('Ver la parrilla')
                ->icon('heroicon-o-calendar-days')
                ->color('gray')
                ->url(PublicacionResource::getUrl('index')),

            CreateAction::make()->label('Nueva publicación'),
        ];
    }
}
