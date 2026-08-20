<?php

namespace App\Filament\Resources\Sales\Pages;

use App\Filament\Resources\Sales\SaleResource;
use App\Models\Sale;
use Filament\Resources\Pages\CreateRecord;

class CreateSale extends CreateRecord
{
    protected static string $resource = SaleResource::class;

    public function getSubheading(): ?string
    {
        return 'Arma el carrito y cóbralo desde el listado. Nada se descuenta hasta ese momento.';
    }

    /** El código y quién atiende se derivan; el estado nace abierto. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $ano = now(config('fabos.lab.timezone'))->year;
        $ultimo = Sale::where('code', 'like', "VTA-{$ano}-%")->max('code');

        $data['code'] = sprintf('VTA-%d-%04d', $ano, $ultimo ? ((int) substr($ultimo, -4)) + 1 : 1);
        $data['served_by'] = auth()->id();
        $data['status'] = 'abierta';

        return $data;
    }
}
