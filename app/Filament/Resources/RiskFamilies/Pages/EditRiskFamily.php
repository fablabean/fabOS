<?php

namespace App\Filament\Resources\RiskFamilies\Pages;

use App\Filament\Resources\RiskFamilies\RiskFamilyResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRiskFamily extends EditRecord
{
    protected static string $resource = RiskFamilyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
