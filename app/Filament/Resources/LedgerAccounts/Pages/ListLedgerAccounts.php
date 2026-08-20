<?php

namespace App\Filament\Resources\LedgerAccounts\Pages;

use App\Filament\Resources\LedgerAccounts\LedgerAccountResource;
use Filament\Resources\Pages\ListRecords;

class ListLedgerAccounts extends ListRecords
{
    protected static string $resource = LedgerAccountResource::class;

    public function getSubheading(): ?string
    {
        return 'Los saldos no se guardan: se calculan sumando los asientos. '
            . 'Por eso no hay forma de "corregir" un saldo sin dejar rastro.';
    }
}
