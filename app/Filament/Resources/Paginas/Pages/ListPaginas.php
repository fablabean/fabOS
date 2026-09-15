<?php

namespace App\Filament\Resources\Paginas\Pages;

use App\Filament\Resources\Paginas\PaginaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPaginas extends ListRecords
{
    protected static string $resource = PaginaResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Escribir una página')];
    }
}
