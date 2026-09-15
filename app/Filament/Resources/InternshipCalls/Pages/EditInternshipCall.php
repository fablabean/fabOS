<?php

namespace App\Filament\Resources\InternshipCalls\Pages;

use App\Filament\Resources\InternshipCalls\InternshipCallResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditInternshipCall extends EditRecord
{
    protected static string $resource = InternshipCallResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
