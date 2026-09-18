<?php

namespace App\Filament\Resources\Matriculas\Pages;

use App\Filament\Resources\Matriculas\MatriculaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMatriculas extends ListRecords
{
    protected static string $resource = MatriculaResource::class;

    public function getSubheading(): ?string
    {
        return 'Quién está en qué programa de Educación Continua. Al matricular, la persona recibe la subcategoría del programa y el saldo con el que arranca.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Matricular a alguien'),
        ];
    }
}
