<?php

namespace App\Filament\Resources\RiskFamilies\Pages;

use App\Filament\Resources\RiskFamilies\RiskFamilyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRiskFamilies extends ListRecords
{
    protected static string $resource = RiskFamilyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
