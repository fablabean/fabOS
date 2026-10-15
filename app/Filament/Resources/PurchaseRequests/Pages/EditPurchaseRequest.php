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
    /**
     * La requisición y sus líneas se guardan juntas o no se guardan.
     *
     * Una línea que fallaba —un título de Amazon más largo que la columna—
     * dejaba la requisición creada y vacía, y quien reintentaba creaba otra.
     */
    protected ?bool $hasDatabaseTransactions = true;

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
