<?php

namespace App\Filament\Resources\Zonas\Pages;

use App\Filament\Resources\Zonas\ZonaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListZonas extends ListRecords
{
    protected static string $resource = ZonaResource::class;

    public function getSubheading(): ?string
    {
        return 'El material del laboratorio por tema. Cada zona tiene su dirección —su subdominio cuando esté activo— y sus módulos: presentaciones de una sola página.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nueva zona')];
    }
}
