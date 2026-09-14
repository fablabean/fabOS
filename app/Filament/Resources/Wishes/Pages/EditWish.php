<?php

namespace App\Filament\Resources\Wishes\Pages;

use App\Filament\Resources\Wishes\WishResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWish extends EditRecord
{
    protected static string $resource = WishResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
