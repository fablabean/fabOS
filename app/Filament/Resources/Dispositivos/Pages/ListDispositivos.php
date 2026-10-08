<?php

namespace App\Filament\Resources\Dispositivos\Pages;

use App\Filament\Resources\Dispositivos\DispositivoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDispositivos extends ListRecords
{
    protected static string $resource = DispositivoResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
