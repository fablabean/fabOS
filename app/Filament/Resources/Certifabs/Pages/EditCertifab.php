<?php

namespace App\Filament\Resources\Certifabs\Pages;

use App\Filament\Resources\Certifabs\CertifabResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCertifab extends EditRecord
{
    protected static string $resource = CertifabResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
