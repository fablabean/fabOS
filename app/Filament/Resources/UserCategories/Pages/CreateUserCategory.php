<?php

namespace App\Filament\Resources\UserCategories\Pages;

use App\Filament\Resources\UserCategories\UserCategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUserCategory extends CreateRecord
{
    protected static string $resource = UserCategoryResource::class;
}
