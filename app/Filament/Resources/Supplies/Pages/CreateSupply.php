<?php

namespace App\Filament\Resources\Supplies\Pages;

use App\Filament\Resources\Supplies\SupplyResource;
use App\Services\Inventory\StockService;
use Filament\Resources\Pages\CreateRecord;

class CreateSupply extends CreateRecord
{
    protected static string $resource = SupplyResource::class;

    /** Lo que se escribió como existencia inicial, para anotarlo después. */
    private float $inicial = 0;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->inicial = (float) ($data['existencia_inicial'] ?? 0);

        unset($data['existencia_inicial']);

        return $data;
    }

    /**
     * La existencia inicial entra como un **movimiento**, no como un número.
     *
     * Escribirla directamente en `stock` dejaría una existencia que nadie puede
     * explicar: el listado de movimientos empezaría en cero mientras el
     * inventario dice cuarenta. Como entrada, el primer día del insumo tiene la
     * misma trazabilidad que el resto de su vida.
     */
    protected function afterCreate(): void
    {
        if ($this->inicial <= 0) {
            return;
        }

        app(StockService::class)->entrada(
            $this->record,
            $this->inicial,
            'Existencia inicial al crear el insumo',
            $this->record,
            auth()->user(),
        );
    }
}
