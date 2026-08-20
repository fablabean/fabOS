<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Presupuesto anual del laboratorio (§13).
 *
 * El saldo NO se guarda: se deriva de las solicitudes, igual que en el libro
 * contable. Un campo «disponible» editable a mano es exactamente lo que hace
 * que a mitad de año nadie sepa cuánto queda de verdad.
 */
class Budget extends Model
{
    protected $fillable = ['name', 'year', 'area_id', 'amount', 'status', 'notes'];

    public const ESTADOS = [
        'borrador' => 'Borrador',
        'vigente'  => 'Vigente',
        'cerrado'  => 'Cerrado',
    ];

    /** Solicitudes que ya reservan plata pero cuya compra no ha llegado. */
    private const COMPROMETEN = ['aprobada', 'en_compra', 'recibida_parcial'];

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(PurchaseRequest::class);
    }

    /**
     * Comprometido: aprobado y todavía sin recibir.
     *
     * Se cuenta lo pendiente de cada solicitud, no su total: si de diez rollos
     * llegaron seis, solo cuatro siguen comprometidos.
     */
    public function comprometido(): int
    {
        return $this->requests()
            ->whereIn('status', self::COMPROMETEN)
            ->with('items')
            ->get()
            ->sum(fn (PurchaseRequest $s) => $s->pendienteEnPesos());
    }

    /** Ejecutado: lo que efectivamente llegó, al precio con el que se pidió. */
    public function ejecutado(): int
    {
        return $this->requests()
            ->whereIn('status', ['recibida_parcial', 'recibida'])
            ->with('items')
            ->get()
            ->sum(fn (PurchaseRequest $s) => $s->recibidoEnPesos());
    }

    public function disponible(): int
    {
        return $this->amount - $this->comprometido() - $this->ejecutado();
    }

    /** Cuánto se ha usado, para pintar una barra sin dividir por cero. */
    public function porcentajeUsado(): float
    {
        if ($this->amount <= 0) {
            return 0;
        }

        return round((($this->comprometido() + $this->ejecutado()) / $this->amount) * 100, 1);
    }
}
