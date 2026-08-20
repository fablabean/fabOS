<?php

namespace App\Filament\Resources\Certifabs\Pages;

use App\Filament\Resources\Certifabs\CertifabResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCertifabs extends ListRecords
{
    protected static string $resource = CertifabResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
