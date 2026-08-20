<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Transacción del libro: inmutable y encadenada por hash (§12). */
class LedgerTransaction extends Model
{
    protected $fillable = [
        'uuid', 'kind', 'reference_type', 'reference_id', 'idempotency_key',
        'memo', 'occurred_at', 'created_by', 'prev_hash', 'hash',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => UtcDateTime::class];
    }

    public const TIPOS = [
        'dotacion'      => 'Dotación institucional',
        'bonificacion'  => 'Bonificación por colaboración',
        'recarga'       => 'Recarga con dinero',
        'compromiso'    => 'Compromiso por reserva',
        'liquidacion'   => 'Liquidación de consumo',
        'venta'         => 'Venta en la tienda',
        'devolucion'    => 'Devolución',
        'ajuste'        => 'Ajuste administrativo',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Total movido, para mostrarlo sin sumar asientos a mano. */
    public function importeMenor(): int
    {
        return (int) $this->entries()->where('direction', 'D')->sum('amount_minor');
    }
}
