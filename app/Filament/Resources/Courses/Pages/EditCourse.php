<?php

namespace App\Filament\Resources\Courses\Pages;

use App\Filament\Resources\Courses\CourseResource;
use App\Filament\Resources\Courses\Tables\CoursesTable;
use App\Models\Course;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCourse extends EditRecord
{
    protected static string $resource = CourseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // El mismo freno que en la lista: con gente inscrita no se borra.
            DeleteAction::make()
                ->tooltip(fn (Course $record) => $record->porQueNoSeBorra())
                ->disabled(fn (Course $record) => ! $record->sePuedeBorrar())
                ->modalDescription(fn (Course $record) => CoursesTable::queSeVa($record)),
        ];
    }
}
