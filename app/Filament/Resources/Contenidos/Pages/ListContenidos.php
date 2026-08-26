<?php

namespace App\Filament\Resources\Contenidos\Pages;

use App\Filament\Resources\Contenidos\ContenidoResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListContenidos extends ListRecords
{
    protected static string $resource = ContenidoResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return 'Lo que se graba en el laboratorio, con la autorización de uso de quien lo grabó. '
            . 'Se sube desde el teléfono, en /contenido.';
    }
}
