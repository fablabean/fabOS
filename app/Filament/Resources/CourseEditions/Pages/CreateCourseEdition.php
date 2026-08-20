<?php

namespace App\Filament\Resources\CourseEditions\Pages;

use App\Filament\Resources\CourseEditions\CourseEditionResource;
use App\Services\Training\TrainingService;
use Filament\Resources\Pages\CreateRecord;

class CreateCourseEdition extends CreateRecord
{
    protected static string $resource = CourseEditionResource::class;

    /** El código se deriva: consecutivo por año y legible por teléfono. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['code'] = $data['code'] ?: app(TrainingService::class)->siguienteCodigo();

        return $data;
    }
}
