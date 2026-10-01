<?php

namespace App\Filament\Resources\Partidas\Pages;

use App\Filament\Resources\Partidas\PartidaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPartidas extends ListRecords
{
    protected static string $resource = PartidaResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
