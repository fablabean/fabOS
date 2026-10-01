<?php

namespace App\Filament\Resources\Circuitos\Pages;

use App\Filament\Resources\Circuitos\CircuitoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCircuitos extends ListRecords
{
    protected static string $resource = CircuitoResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
