<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un descuento o un cobro adicional sobre una solicitud de compra (§13).
 *
 * No es una linea del carrito: no tiene cantidad ni precio unitario. Es lo
 * que el proveedor resta o suma sobre el pedido entero —el descuento de
 * Amazon, el envio, un cargo de importacion— y sin lo cual la cuenta no da.
 */
class PurchaseRequestAdjustment extends Model
{
    protected $table = 'purchase_request_adjustments';

    protected $fillable = ['purchase_request_id', 'kind', 'description', 'amount', 'applies_tax', 'sort'];

    public const DESCUENTO = 'descuento';
    public const COBRO     = 'cobro';

    public const TIPOS = [
        self::DESCUENTO => 'Descuento',
        self::COBRO     => 'Cobro adicional',
    ];

    protected function casts(): array
    {
        return [
            'amount'      => 'decimal:2',
            'applies_tax' => 'boolean',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    }

    public function esDescuento(): bool
    {
        return $this->kind === self::DESCUENTO;
    }

    /** Con signo: negativo si resta, positivo si suma. */
    public function conSigno(): float
    {
        $monto = abs((float) $this->amount);

        return $this->esDescuento() ? -$monto : $monto;
    }
}
