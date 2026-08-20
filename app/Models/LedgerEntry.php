<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Asiento: un movimiento contra una cuenta (§12). */
class LedgerEntry extends Model
{
    protected $fillable = ['ledger_transaction_id', 'ledger_account_id', 'direction', 'amount_minor'];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class, 'ledger_transaction_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    public function esDebito(): bool
    {
        return $this->direction === 'D';
    }
}
