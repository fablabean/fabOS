<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Solicitud de compra: el carrito que se le entrega al área de compras (§13).
 *
 * Nace como borrador —un carrito al que se le van echando cosas—, se envía, se
 * aprueba contra un presupuesto y luego se recibe, casi siempre por partes.
 */
class PurchaseRequest extends Model
{
    protected $fillable = [
        'code', 'budget_id', 'project_id', 'area_id', 'requested_by', 'approved_by',
        'status', 'justification', 'notes',
        'submitted_at', 'decided_at', 'decision_reason', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => UtcDateTime::class,
            'decided_at'   => UtcDateTime::class,
            'closed_at'    => UtcDateTime::class,
        ];
    }

    public const ESTADOS = [
        'borrador'         => 'Borrador',
        'enviada'          => 'Enviada',
        'aprobada'         => 'Aprobada',
        'rechazada'        => 'Rechazada',
        'en_compra'        => 'En compra',
        'recibida_parcial' => 'Recibida en parte',
        'recibida'         => 'Recibida',
        'cancelada'        => 'Cancelada',
    ];

    /** Estados en los que todavía se puede editar el carrito. */
    public const EDITABLES = ['borrador', 'enviada'];

    /** Estados en los que la solicitud ya no reserva ni ejecuta plata. */
    public const CERRADAS = ['rechazada', 'cancelada', 'recibida'];

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseRequestItem::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** Subtotal en pesos, sin impuesto. */
    public function subtotal(): int
    {
        return (int) $this->items->sum(fn (PurchaseRequestItem $i) => $i->total());
    }

    /**
     * Total estimado con impuesto.
     *
     * Compras trabaja con el valor con IVA; presentar el subtotal a secas hace
     * que el presupuesto parezca alcanzar para más de lo que alcanza.
     */
    public function totalEstimado(): int
    {
        return (int) round($this->subtotal() * (1 + config('fabos.money.tax_rate')));
    }

    /** Lo que ya llegó, valorado al precio con el que se pidió. */
    public function recibidoEnPesos(): int
    {
        return (int) round(
            $this->items->sum(fn (PurchaseRequestItem $i) => $i->received_quantity * $i->unit_price)
            * (1 + config('fabos.money.tax_rate'))
        );
    }

    /** Lo que sigue comprometido: pedido menos recibido. */
    public function pendienteEnPesos(): int
    {
        return max(0, $this->totalEstimado() - $this->recibidoEnPesos());
    }

    public function estaCompleta(): bool
    {
        return $this->items->isNotEmpty()
            && $this->items->every(fn (PurchaseRequestItem $i) => $i->pendiente() <= 0);
    }

    public function esEditable(): bool
    {
        return in_array($this->status, self::EDITABLES, true);
    }
}
