<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Movimiento de existencias (§7).
 *
 * Guarda el saldo resultante junto al movimiento: así el histórico se puede
 * leer sin recalcular toda la cadena, y un descuadre entre `stock` y el último
 * `balance_after` es la señal de que alguien tocó la existencia por fuera.
 */
class SupplyMovement extends Model
{
    protected $fillable = [
        'supply_id', 'kind', 'quantity', 'balance_after',
        'reference_type', 'reference_id', 'created_by', 'memo',
    ];

    protected function casts(): array
    {
        return [
            'quantity'      => 'decimal:3',
            'balance_after' => 'decimal:3',
        ];
    }

    public const TIPOS = [
        'entrada' => 'Entrada',
        'salida'  => 'Salida',
        'ajuste'  => 'Ajuste de inventario',
    ];

    public function supply(): BelongsTo
    {
        return $this->belongsTo(Supply::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
