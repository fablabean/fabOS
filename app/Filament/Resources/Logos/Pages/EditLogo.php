<?php

namespace App\Filament\Resources\Logos\Pages;

use App\Filament\Resources\Logos\LogoResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLogo extends EditRecord
{
    protected static string $resource = LogoResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function getRedirectUrl(): string
    {
        return LogoResource::getUrl();
    }
}
