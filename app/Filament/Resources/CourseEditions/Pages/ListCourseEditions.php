<?php

namespace App\Filament\Resources\CourseEditions\Pages;

use App\Filament\Resources\CourseEditions\CourseEditionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCourseEditions extends ListRecords
{
    protected static string $resource = CourseEditionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
