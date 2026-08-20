<?php

namespace App\Filament\Resources\PurchaseRequests\Pages;

use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Models\PurchaseRequest;
use Filament\Resources\Pages\CreateRecord;

class CreatePurchaseRequest extends CreateRecord
{
    protected static string $resource = PurchaseRequestResource::class;

    public function getSubheading(): ?string
    {
        return 'Arma el carrito. Nada queda comprometido hasta que se apruebe.';
    }

    /** El código y el solicitante no se escriben a mano: se derivan. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['code'] = $this->siguienteCodigo();
        $data['requested_by'] = auth()->id();
        $data['status'] = 'borrador';

        return $data;
    }

    private function siguienteCodigo(): string
    {
        $ano = now(config('fabos.lab.timezone'))->year;
        $ultimo = PurchaseRequest::where('code', 'like', "COM-{$ano}-%")->max('code');

        return sprintf('COM-%d-%04d', $ano, $ultimo ? ((int) substr($ultimo, -4)) + 1 : 1);
    }
}
