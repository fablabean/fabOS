<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Reserva de un recurso durante un rango de tiempo (§10).
 *
 * La no superposición no se valida aquí: la garantiza PostgreSQL con una
 * restricción EXCLUDE. Si dos reservas chocan, el INSERT falla.
 */
class Reservation extends Model
{
    protected $fillable = [
        'reservable_type', 'reservable_id', 'user_id', 'project_id', 'supervisor_id',
        'advisory_asset_id', 'participants', 'parent_reservation_id',
        'status', 'mode', 'starts_at', 'ends_at',
        'checked_in_at', 'checked_out_at',
        'estimated_cost_minor', 'actual_cost_minor', 'purpose', 'status_reason',
    ];

    protected function casts(): array
    {
        return [
            'starts_at'      => UtcDateTime::class,
            'ends_at'        => UtcDateTime::class,
            'checked_in_at'  => UtcDateTime::class,
            'checked_out_at' => UtcDateTime::class,
        ];
    }

    public const ESTADOS = [
        'solicitada'  => 'Solicitada',
        'confirmada'  => 'Confirmada',
        'en_curso'    => 'En curso',
        'completada'  => 'Completada',
        'rechazada'   => 'Rechazada',
        'cancelada'   => 'Cancelada',
        'no_show'     => 'No se presentó',
    ];

    public const MODOS = [
        'directa'        => 'Directa',
        'asesoria'       => 'Asesoría',
        'con_aprobacion' => 'Con aprobación',
        'solo_solicitud' => 'Solo por solicitud',
    ];

    /** Estados en los que la reserva ocupa el recurso de verdad. */
    public const BLOQUEANTES = ['confirmada', 'en_curso'];

    /** Un proyecto al que se carga esta reserva, si lo hay (§11). */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function reservable(): MorphTo
    {
        return $this->morphTo();
    }

    /** El bloque del colaborador que acompaña esta reserva, si lo hay. */
    public function acompanamiento(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(self::class, 'parent_reservation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    /** El equipo sobre el que trata una asesoría; nulo en el resto (§10). */
    public function advisoryAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'advisory_asset_id');
    }

    public function esAsesoria(): bool
    {
        return $this->mode === 'asesoria';
    }
}
