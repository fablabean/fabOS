<?php

namespace App\Filament\Resources\PurchaseRequests\Pages;

use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Models\PurchaseRequest;
use App\Services\Purchasing\PurchasingService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListPurchaseRequests extends ListRecords
{
    protected static string $resource = PurchaseRequestResource::class;

    public function getSubheading(): ?string
    {
        return 'Lo que sale de aquí es la requisición que se le entrega al área de compras de la Universidad.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nuevo carrito'),

            // Atajo para el caso más frecuente: reponer lo que se está acabando.
            Action::make('reposicion')
                ->label('Carrito de reposición')
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Armar un carrito con lo que está bajo mínimos')
                ->modalDescription('Se crea un borrador con cada insumo por debajo de su punto de reposición, en cantidad suficiente para dejar colchón. Después se revisa y se ajusta.')
                ->action(function () {
                    $compras = app(PurchasingService::class);
                    $carrito = $compras->abrirCarrito(auth()->user(), null, 'Reposición de insumos bajo mínimos');
                    $n = $compras->llenarConLoQueFalta($carrito);

                    if ($n === 0) {
                        $carrito->delete();

                        Notification::make()
                            ->title('No hay nada bajo mínimos')
                            ->body('Ningún insumo está por debajo de su punto de reposición.')
                            ->success()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title("Carrito {$carrito->code} con {$n} líneas")
                        ->body('Revísalo y ajústalo antes de enviarlo.')
                        ->success()
                        ->send();

                    $this->redirect(PurchaseRequestResource::getUrl('edit', ['record' => $carrito]));
                }),
        ];
    }
}
