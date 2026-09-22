<?php

namespace App\Filament\Resources\ShiftAssignments\Pages;

use App\Filament\Resources\ShiftAssignments\ShiftAssignmentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateShiftAssignment extends CreateRecord
{
    protected static string $resource = ShiftAssignmentResource::class;

    /** Quién la asignó es quien la crea: no se teclea un id. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['assigned_by'] = auth()->id();

        return $data;
    }
}
