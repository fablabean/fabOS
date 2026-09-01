<?php

namespace App\Filament\Resources\ShortLinks\Pages;

use App\Filament\Resources\ShortLinks\ShortLinkResource;
use Filament\Resources\Pages\CreateRecord;

class CreateShortLink extends CreateRecord
{
    protected static string $resource = ShortLinkResource::class;

    /** Quien lo creo queda escrito: un codigo pegado en un cartel tiene dueño. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }
}
