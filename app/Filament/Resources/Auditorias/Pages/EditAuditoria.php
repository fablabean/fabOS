<?php

namespace App\Filament\Resources\Auditorias\Pages;

use App\Filament\Resources\Auditorias\AuditoriaResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAuditoria extends EditRecord
{
    protected static string $resource = AuditoriaResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
