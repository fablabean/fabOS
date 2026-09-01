<?php

namespace App\Filament\Resources\Banners\Pages;

use App\Filament\Resources\Banners\BannerResource;
use App\Models\Banner;
use Filament\Resources\Pages\CreateRecord;

class CreateBanner extends CreateRecord
{
    protected static string $resource = BannerResource::class;

    /** La nueva va al final: quien la crea decide despues donde ponerla. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['position'] ??= (int) Banner::max('position') + 1;

        return $data;
    }
}
