<?php

namespace App\Filament\Resources\LedgerTransactions\Pages;

use App\Filament\Resources\LedgerTransactions\LedgerTransactionResource;
use App\Services\Ledger\LedgerService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListLedgerTransactions extends ListRecords
{
    protected static string $resource = LedgerTransactionResource::class;

    public function getSubheading(): ?string
    {
        return 'Cada movimiento sella el anterior. Alterar uno viejo rompe el sello de todos los que vinieron después.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('verificar')
                ->label('Verificar la cadena')
                ->icon('heroicon-o-shield-check')
                ->color('gray')
                ->action(function () {
                    $veredicto = app(LedgerService::class)->verificarCadena();

                    $veredicto['intacta']
                        ? Notification::make()
                            ->title('La cadena está intacta')
                            ->body('Ningún movimiento fue alterado desde que se escribió.')
                            ->success()
                            ->send()
                        : Notification::make()
                            ->title('La cadena está rota')
                            ->body('El movimiento #' . $veredicto['rota_en'] . ' no coincide con su sello. Revisar antes de seguir operando.')
                            ->danger()
                            ->persistent()
                            ->send();
                }),
        ];
    }
}
