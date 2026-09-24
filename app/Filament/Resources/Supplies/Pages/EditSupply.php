<?php

namespace App\Filament\Resources\Supplies\Pages;

use App\Filament\Acciones\GenerarIlustracion;
use App\Filament\Resources\Supplies\SupplyResource;
use App\Services\Money\PricingService;
use App\Support\Dinero;
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
            GenerarIlustracion::make(),
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
        // En unidades menores: el campo lo pinta en la moneda de trabajo.
        $pesos = app(PricingService::class)->precioEnPesosDe($this->record);
        $data['precio_venta'] = $pesos === null ? null : Dinero::aMenor($pesos, 'pesos');

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Y vuelve a pesos, que es como se fija la tarifa del insumo.
        $this->precio = filled($data['precio_venta'] ?? null)
            ? (int) round(Dinero::enMoneda((float) $data['precio_venta'], 'pesos'))
            : null;

        unset($data['precio_venta']);

        return $data;
    }

    protected function afterSave(): void
    {
        app(PricingService::class)->fijarPrecioEnPesos($this->record, $this->precio);
    }
}
