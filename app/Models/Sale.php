<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Una venta del mostrador (§14). */
class Sale extends Model
{
    protected $fillable = [
        'code', 'user_id', 'served_by', 'status', 'total_minor',
        'paid_at', 'voided_at', 'void_reason', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'paid_at'   => UtcDateTime::class,
            'voided_at' => UtcDateTime::class,
        ];
    }

    public const ESTADOS = [
        'abierta' => 'Abierta',
        'pagada'  => 'Pagada',
        'anulada' => 'Anulada',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function servedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'served_by');
    }

    /** Total de lo que hay en el carrito ahora mismo. */
    public function totalMenor(): int
    {
        return (int) $this->items->sum(fn (SaleItem $i) => $i->totalMenor());
    }

    /**
     * Lo que se cobra: el total congelado si ya se pagó, o el del carrito.
     *
     * Sin esto, subir una tarifa mañana reescribiria el valor de las ventas de
     * ayer y los cierres dejarian de cuadrar.
     */
    public function total(): float
    {
        $menor = $this->status === 'pagada' ? $this->total_minor : $this->totalMenor();

        return $menor / config('fabos.currency.minor_units');
    }

    public function esEditable(): bool
    {
        return $this->status === 'abierta';
    }
}
