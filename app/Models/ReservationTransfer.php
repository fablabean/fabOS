<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una propuesta de pasarle a otra persona lo que a uno le toca atender (§10).
 *
 * Nace pendiente y termina de una de tres maneras: quien la recibe la acepta
 * o la rechaza, o quien la hizo la retira. Solo al aceptarla cambia la
 * reserva de manos.
 */
class ReservationTransfer extends Model
{
    protected $table = 'reservation_transfers';

    protected $fillable = [
        'reservation_id', 'from_user_id', 'to_user_id', 'status', 'note', 'answer', 'decided_at',
    ];

    public const PENDIENTE = 'pendiente';
    public const ACEPTADO  = 'aceptado';
    public const RECHAZADO = 'rechazado';
    public const RETIRADO  = 'retirado';

    public const ESTADOS = [
        self::PENDIENTE => 'Pendiente',
        self::ACEPTADO  => 'Aceptado',
        self::RECHAZADO => 'Rechazado',
        self::RETIRADO  => 'Retirado',
    ];

    protected function casts(): array
    {
        return ['decided_at' => UtcDateTime::class];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /** Quien la tenia y quiere soltarla. */
    public function from(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    /** A quien se la proponen; es quien decide. */
    public function to(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function estaPendiente(): bool
    {
        return $this->status === self::PENDIENTE;
    }

    public function scopePendientes($query)
    {
        return $query->where('status', self::PENDIENTE);
    }
}
