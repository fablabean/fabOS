<?php

namespace App\Filament\Resources\ProfessionalProfiles\Pages;

use App\Filament\Resources\ProfessionalProfiles\ProfessionalProfileResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProfessionalProfiles extends ListRecords
{
    protected static string $resource = ProfessionalProfileResource::class;

    public function getSubheading(): ?string
    {
        return 'Quién puede trabajar con el laboratorio. De aquí sale la hoja que se le manda a compras de la Universidad para inscribirlo como proveedor.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Añadir un perfil'),
        ];
    }
}
