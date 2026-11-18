<?php

namespace App\Filament\Resources\Pantallas\Pages;

use App\Filament\Resources\Pantallas\PantallaResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePantalla extends CreateRecord
{
    protected static string $resource = PantallaResource::class;

    /** Recién creada sigue en la ficha: ahí se genera la clave del ESP32. */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
