<?php

namespace App\Filament\Resources\Supplies\Pages;

use App\Filament\Resources\Supplies\SupplyResource;
use App\Services\Money\PricingService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSupply extends EditRecord
{
    protected static string $resource = SupplyResource::class;

    /** El precio de venta: no es columna del insumo, es una tarifa. */
    private ?int $precio = null;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * El precio vive en la tarifa, pero se edita donde se decide vender.
     *
     * Mandar a quien pone un precio a otra pantalla es lo que hace que la
     * mitad de los insumos publicados se queden sin él.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['precio_venta'] = app(PricingService::class)->precioEnPesosDe($this->record);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->precio = filled($data['precio_venta'] ?? null) ? (int) $data['precio_venta'] : null;

        unset($data['precio_venta']);

        return $data;
    }

    protected function afterSave(): void
    {
        app(PricingService::class)->fijarPrecioEnPesos($this->record, $this->precio);
    }
}
