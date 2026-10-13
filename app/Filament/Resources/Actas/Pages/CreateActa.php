<?php

namespace App\Filament\Resources\Actas\Pages;

use App\Filament\Resources\Actas\ActaResource;
use Filament\Resources\Pages\CreateRecord;

class CreateActa extends CreateRecord
{
    protected static string $resource = ActaResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }

    /** A la lista: ahí está el botón de enviarla a firma. */
    protected function getRedirectUrl(): string
    {
        return ActaResource::getUrl('index');
    }
}
