<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Insumo: lo que se consume y se repone (§7, §13).
 *
 * Distinto de un activo. Un activo es una unidad identificable con placa y QR;
 * un insumo es una cantidad. No tiene sentido ponerle placa a un rollo de
 * filamento, pero sí saber cuánto queda y cuándo pedir más.
 */
class Supply extends Model
{
    protected $fillable = [
        'area_id', 'location_id', 'name', 'sku', 'unit', 'description',
        'stock', 'reorder_point', 'last_cost', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'stock'         => 'decimal:3',
            'reorder_point' => 'decimal:3',
            'is_active'     => 'boolean',
        ];
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(SupplyMovement::class);
    }

    /** Bajo mínimos: es lo que dispara la siguiente compra. */
    public function bajoMinimos(): bool
    {
        return $this->reorder_point !== null
            && (float) $this->stock <= (float) $this->reorder_point;
    }
}
