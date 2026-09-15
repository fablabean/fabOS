<?php

namespace App\Filament\Resources\ProfessionalProfiles\Pages;

use App\Filament\Resources\ProfessionalProfiles\ProfessionalProfileResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProfessionalProfile extends CreateRecord
{
    protected static string $resource = ProfessionalProfileResource::class;

    public function getSubheading(): ?string
    {
        return 'Con el nombre basta para empezar. Lo demás se llena cuando se consigue, y la lista dice qué falta.';
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }
}
