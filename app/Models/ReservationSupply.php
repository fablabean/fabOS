<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Material gastado en una reserva, con su precio congelado (§12). */
class ReservationSupply extends Model
{
    protected $fillable = ['reservation_id', 'supply_id', 'quantity', 'unit_price_minor'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function supply(): BelongsTo
    {
        return $this->belongsTo(Supply::class);
    }

    public function totalMenor(): int
    {
        return (int) round((float) $this->quantity * $this->unit_price_minor);
    }
}
