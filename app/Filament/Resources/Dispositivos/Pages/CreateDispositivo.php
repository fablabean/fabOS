<?php

namespace App\Filament\Resources\Dispositivos\Pages;

use App\Filament\Resources\Dispositivos\DispositivoResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDispositivo extends CreateRecord
{
    protected static string $resource = DispositivoResource::class;

    /** Recién creado sigue en la ficha: ahí se genera la clave de la Raspberry. */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
