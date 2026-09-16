<?php

namespace App\Filament\Resources\Credenciales\Pages;

use App\Filament\Resources\Credenciales\Actions\RevelarElSecreto;
use App\Filament\Resources\Credenciales\CredencialResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCredencial extends EditRecord
{
    protected static string $resource = CredencialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RevelarElSecreto::make(),
            DeleteAction::make(),
        ];
    }
}
