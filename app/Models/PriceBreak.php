<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Un escalón de precio por cantidad (§14).
 *
 * «De 10 en adelante, a $20.000 cada uno.» Guarda el precio, no el descuento:
 * un porcentaje se mueve solo cuando cambia el precio base, y entonces se cobra
 * algo que nadie decidió. El descuento se calcula para enseñarlo.
 */
class PriceBreak extends Model
{
    protected $fillable = ['priceable_type', 'priceable_id', 'min_quantity', 'price_minor'];

    protected function casts(): array
    {
        return [
            'min_quantity' => 'decimal:3',
            'price_minor'  => 'integer',
        ];
    }

    public function priceable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Cuánto se ahorra frente al precio de una sola, en porcentaje. */
    public function descuentoSobre(int $precioBase): float
    {
        if ($precioBase <= 0 || $this->price_minor >= $precioBase) {
            return 0;
        }

        return round((1 - $this->price_minor / $precioBase) * 100, 1);
    }
}
