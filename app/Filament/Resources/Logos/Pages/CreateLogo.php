<?php

namespace App\Filament\Resources\Logos\Pages;

use App\Filament\Resources\Logos\LogoResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLogo extends CreateRecord
{
    protected static string $resource = LogoResource::class;

    protected function getRedirectUrl(): string
    {
        return LogoResource::getUrl();
    }
}
