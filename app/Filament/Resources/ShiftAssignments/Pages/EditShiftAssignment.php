<?php

namespace App\Filament\Resources\ShiftAssignments\Pages;

use App\Filament\Resources\ShiftAssignments\ShiftAssignmentResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditShiftAssignment extends EditRecord
{
    protected static string $resource = ShiftAssignmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Lo que ya fue en un reporte de nómina no se borra.
            DeleteAction::make()->hidden(fn () => $this->record->reporte_id !== null),
        ];
    }
}
