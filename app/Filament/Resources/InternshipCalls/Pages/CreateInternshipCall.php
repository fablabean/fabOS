<?php

namespace App\Filament\Resources\InternshipCalls\Pages;

use App\Filament\Resources\InternshipCalls\InternshipCallResource;
use Filament\Resources\Pages\CreateRecord;

class CreateInternshipCall extends CreateRecord
{
    protected static string $resource = InternshipCallResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }
}
