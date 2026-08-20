<?php

namespace App\Filament\Resources\RateCards\Pages;

use App\Filament\Resources\RateCards\RateCardResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRateCard extends EditRecord
{
    protected static string $resource = RateCardResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
