<?php

namespace App\Filament\Resources\Logos\Pages;

use App\Filament\Resources\Logos\LogoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLogos extends ListRecords
{
    protected static string $resource = LogoResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Añadir logo')];
    }
}
