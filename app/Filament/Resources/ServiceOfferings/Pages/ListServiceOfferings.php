<?php

namespace App\Filament\Resources\ServiceOfferings\Pages;

use App\Filament\Resources\ServiceOfferings\ServiceOfferingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListServiceOfferings extends ListRecords
{
    protected static string $resource = ServiceOfferingResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return 'Trabajos con precio cerrado, para quien no sabe operar la máquina ni tiene por qué. '
            . 'Salen en la tienda pública.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nuevo servicio')];
    }
}
