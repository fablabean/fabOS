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

            // Lo que reciben, por escrito, para enviárselo a Educación Continua.
            \Filament\Actions\Action::make('beneficios')
                ->label('Beneficios (PDF)')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->url(fn () => \App\Filament\Pages\BeneficiosEducacionContinua::getUrl())
                ->visible(fn () => \App\Filament\Pages\BeneficiosEducacionContinua::canAccess()),
        ];
    }
}
