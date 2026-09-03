<?php

namespace App\Filament\Resources\PurchaseRequests\Pages;

use App\Filament\Resources\PurchaseRequests\Actions\AccionesDeCompartir;
use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Models\PurchaseRequest;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPurchaseRequest extends EditRecord
{
    protected static string $resource = PurchaseRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('requisicion')
                ->label('Ver requisición')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->url(fn (PurchaseRequest $record) => route('compras.requisicion', $record))
                ->openUrlInNewTab(),

            // Compartir vive aqui ademas de en el listado: quien acaba de armar
            // el carrito quiere mandarlo sin volver a buscarlo en la lista.
            AccionesDeCompartir::compartir(),
            AccionesDeCompartir::dejarDeCompartir(),

            DeleteAction::make(),
        ];
    }
}
