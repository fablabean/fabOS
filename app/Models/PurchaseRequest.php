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
        'tax_rate', 'cart_url', 'share_token', 'shared_at',
    ];

    protected function casts(): array
    {
        return [
            'tax_rate' => 'decimal:4',
            'submitted_at' => UtcDateTime::class,
            'decided_at'   => UtcDateTime::class,
            'closed_at'    => UtcDateTime::class,
            'shared_at'    => UtcDateTime::class,
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
     * La tasa de impuesto de ESTA solicitud.
     *
     * Nulo significa «la del laboratorio»: así, cambiar la tasa general el día
     * que cambie la ley arrastra a todo lo que no dijo otra cosa. Pero no todo
     * lo que se pide lleva IVA —unos honorarios, un servicio exento, un régimen
     * simplificado— y sin poder decirlo, quien escribe un valor ve otro más
     * alto y deja de fiarse de la cifra.
     */
    public function tasaDeImpuesto(): float
    {
        return $this->tax_rate === null
            ? (float) config('fabos.money.tax_rate')
            : (float) $this->tax_rate;
    }

    public function impuesto(): int
    {
        return $this->totalEstimado() - $this->subtotal();
    }

    /**
     * Total estimado con impuesto.
     *
     * Compras trabaja con el valor con IVA; presentar el subtotal a secas hace
     * que el presupuesto parezca alcanzar para más de lo que alcanza.
     */
    public function totalEstimado(): int
    {
        return (int) round($this->subtotal() * (1 + $this->tasaDeImpuesto()));
    }

    /** Lo que ya llegó, valorado al precio con el que se pidió. */
    public function recibidoEnPesos(): int
    {
        return (int) round(
            $this->items->sum(fn (PurchaseRequestItem $i) => $i->received_quantity * $i->unit_price)
            * (1 + $this->tasaDeImpuesto())
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

    /** Hay un enlace vivo que cualquiera que lo tenga puede abrir. */
    public function estaCompartida(): bool
    {
        return $this->share_token !== null;
    }

    /**
     * La dirección que se le manda a compras. Nula si no se ha compartido:
     * el enlace no existe hasta que alguien decide que exista.
     */
    public function enlaceCompartido(): ?string
    {
        return $this->share_token ? route('compras.compartida', $this->share_token) : null;
    }
}
