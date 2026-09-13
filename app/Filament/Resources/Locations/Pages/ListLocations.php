<?php

namespace App\Filament\Resources\Locations\Pages;

use App\Filament\Resources\Locations\LocationResource;
use App\Filament\Resources\Locations\Widgets\EspaciosConUbicaciones;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLocations extends ListRecords
{
    protected static string $resource = LocationResource::class;

    /**
     * Los espacios, encima de la lista.
     *
     * La lista plana no dice en que sala esta cada mueble, y el espacio no es
     * una columna suya: lo hereda de su raiz.
     */
    protected function getHeaderWidgets(): array
    {
        return [
            EspaciosConUbicaciones::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
