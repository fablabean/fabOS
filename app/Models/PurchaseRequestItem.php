<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una línea del carrito (§13). */
class PurchaseRequestItem extends Model
{
    protected $fillable = [
        'purchase_request_id', 'supply_id', 'description', 'unit', 'quantity',
        'unit_price', 'received_quantity', 'supplier', 'reference_url', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity'          => 'decimal:3',
            'received_quantity' => 'decimal:3',
            // Con centavos: un lanyard en Amazon vale US$18,99.
            'unit_price'        => 'decimal:2',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    }

    public function supply(): BelongsTo
    {
        return $this->belongsTo(Supply::class);
    }

    /** Cantidad por precio, en la moneda de la solicitud. */
    public function total(): float
    {
        return round((float) $this->quantity * (float) $this->unit_price, 2);
    }

    /** Lo que falta por llegar. Nunca negativo: recibir de más no resta. */
    public function pendiente(): float
    {
        return max(0, (float) $this->quantity - (float) $this->received_quantity);
    }
}
