<?php

namespace App\Filament\Resources\CourseEditions\Pages;

use App\Filament\Resources\CourseEditions\CourseEditionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCourseEdition extends EditRecord
{
    protected static string $resource = CourseEditionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
