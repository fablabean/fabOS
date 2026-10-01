<?php

namespace App\Filament\Resources\Partidas\Pages;

use App\Filament\Resources\Partidas\PartidaResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePartida extends CreateRecord
{
    protected static string $resource = PartidaResource::class;

    /** Recién creada sigue en la ficha: ahí están los códigos y el botón de iniciar. */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl("edit", ["record" => $this->record]);
    }
}
