<?php

namespace App\Filament\Resources\PurchaseRequests\Actions;

use App\Models\PurchaseRequest;
use App\Services\Purchasing\PurchasingService;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;

/**
 * Compartir la requisición con el área de compras (§13).
 *
 * Las mismas dos acciones en el listado y en la ficha, para que la pantalla
 * donde uno esté sea la que sirve. Compartir crea un enlace sin sesión —quien
 * lo recibe no tiene cuenta en fabOS— y lo enseña listo para copiar. Dejar de
 * compartir lo revoca: el que ya se mandó deja de abrir.
 */
class AccionesDeCompartir
{
    public static function compartir(): Action
    {
        return Action::make('compartir')
            ->label(fn (PurchaseRequest $record) => $record->estaCompartida() ? 'Enlace para compras' : 'Compartir con compras')
            ->icon('heroicon-o-link')
            ->color(fn (PurchaseRequest $record) => $record->estaCompartida() ? 'gray' : 'primary')
            ->modalHeading('Enlace para el área de compras')
            ->modalDescription('Abre sin sesión y deja bajar la requisición en PDF. Si después se corrige algo aquí, en el enlace se ve corregido: no hay que volver a mandarlo.')
            ->modalWidth('lg')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Cerrar')
            // Abrir el dialogo ES compartir: el enlace se crea al abrirlo,
            // para que este listo para copiar sin un clic mas.
            ->schema(fn (PurchaseRequest $record) => [
                TextEntry::make('enlace')
                    ->label('Enlace')
                    ->state(app(PurchasingService::class)->compartir($record))
                    ->copyable()
                    ->copyMessage('Copiado')
                    ->fontFamily('mono')
                    ->helperText('Un clic lo copia. Pégalo en el correo a compras.'),

                TextEntry::make('pdf')
                    ->label('PDF directo')
                    ->state(route('compras.compartida.pdf', $record->fresh()->share_token))
                    ->copyable()
                    ->copyMessage('Copiado')
                    ->fontFamily('mono')
                    ->helperText('El mismo documento, pero descarga el PDF de una vez.'),
            ]);
    }

    public static function dejarDeCompartir(): Action
    {
        return Action::make('dejarDeCompartir')
            ->label('Dejar de compartir')
            ->icon('heroicon-o-link-slash')
            ->color('gray')
            ->visible(fn (PurchaseRequest $record) => $record->estaCompartida())
            ->requiresConfirmation()
            ->modalHeading('Dejar de compartir')
            ->modalDescription('El enlace que ya se mandó deja de abrir. Si hace falta otro, se comparte de nuevo y sale uno distinto.')
            ->action(function (PurchaseRequest $record) {
                app(PurchasingService::class)->dejarDeCompartir($record);

                Notification::make()->title('Enlace revocado')->success()->send();
            });
    }
}
