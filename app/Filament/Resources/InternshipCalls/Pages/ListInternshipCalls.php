<?php

namespace App\Filament\Resources\InternshipCalls\Pages;

use App\Filament\Resources\InternshipCalls\InternshipCallResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInternshipCalls extends ListRecords
{
    protected static string $resource = InternshipCallResource::class;

    public function getSubheading(): ?string
    {
        return 'Cada semestre, la tanda de quienes quieren hacer su práctica aquí. Se evalúa con la tanda entera delante, y a quien se acepta se le crea cuenta con rol de practicante.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Abrir una convocatoria'),
        ];
    }
}
