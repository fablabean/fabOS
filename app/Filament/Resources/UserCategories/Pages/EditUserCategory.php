<?php

namespace App\Filament\Resources\UserCategories\Pages;

use App\Filament\Resources\UserCategories\UserCategoryResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUserCategory extends EditRecord
{
    protected static string $resource = UserCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
