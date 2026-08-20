<?php

namespace App\Filament\Resources\NotificationLogs\Pages;

use App\Filament\Resources\NotificationLogs\NotificationLogResource;
use Filament\Resources\Pages\ListRecords;

class ListNotificationLogs extends ListRecords
{
    protected static string $resource = NotificationLogResource::class;

    public function getSubheading(): ?string
    {
        return 'Incluye también lo que no se envió y por qué: si alguien reclama que '
            . 'no le avisaron, aquí está la respuesta.';
    }
}
