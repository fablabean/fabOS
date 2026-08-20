<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una línea de la venta (§14). */
class SaleItem extends Model
{
    protected $fillable = [
        'sale_id', 'supply_id', 'description', 'unit', 'quantity', 'unit_price_minor',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function supply(): BelongsTo
    {
        return $this->belongsTo(Supply::class);
    }

    public function totalMenor(): int
    {
        return (int) round((float) $this->quantity * $this->unit_price_minor);
    }

    /** Las líneas de inventario descuentan existencia; los servicios no. */
    public function mueveInventario(): bool
    {
        return $this->supply_id !== null;
    }
}
