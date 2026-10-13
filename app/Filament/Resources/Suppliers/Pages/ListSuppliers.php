<?php

namespace App\Filament\Resources\Suppliers\Pages;

use App\Filament\Resources\Suppliers\SupplierResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSuppliers extends ListRecords
{
    protected static string $resource = SupplierResource::class;

    public function getSubheading(): ?string
    {
        return 'A quién se le compra. Se eligen desde la solicitud de compra, y uno nuevo escrito allí queda guardado aquí.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Añadir un proveedor')];
    }
}
