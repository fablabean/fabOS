<?php

namespace App\Filament\Resources\Assets\Pages;

use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Resources\Assets\Widgets\AreasDelCatalogo;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAssets extends ListRecords
{
    protected static string $resource = AssetResource::class;

    /**
     * Las areas con su foto, encima del catalogo.
     *
     * Ochenta y dos fichas de veinticinco en veinticinco: llegar a las de
     * fresado era pasar paginas o acordarse de un filtro escondido en un
     * desplegable.
     */
    protected function getHeaderWidgets(): array
    {
        return [
            AreasDelCatalogo::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
