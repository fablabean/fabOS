<?php

namespace App\Filament\Resources\Software\Pages;

use App\Filament\Resources\Software\SoftwareResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSoftware extends CreateRecord
{
    protected static string $resource = SoftwareResource::class;

    /** A la ficha: lo siguiente es decir en que equipos esta y quien lo usa. */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
