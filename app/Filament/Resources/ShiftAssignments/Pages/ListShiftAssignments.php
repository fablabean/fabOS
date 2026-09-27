<?php

namespace App\Filament\Resources\ShiftAssignments\Pages;

use App\Filament\Resources\ShiftAssignments\ShiftAssignmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListShiftAssignments extends ListRecords
{
    protected static string $resource = ShiftAssignmentResource::class;

    /** El contador del periodo de corte, encima de la lista. */
    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\Resources\ShiftAssignments\Widgets\ContadorDeExtras::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
