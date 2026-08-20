<?php

namespace App\Filament\Resources\UserCategories\Pages;

use App\Filament\Resources\UserCategories\UserCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUserCategories extends ListRecords
{
    protected static string $resource = UserCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
