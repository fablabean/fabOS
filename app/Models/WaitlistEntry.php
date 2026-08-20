<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alguien esperando que se libere un equipo (§10).
 *
 * Guarda la ventana en la que le sirve, no solo el equipo: avisar de un hueco
 * del martes a quien solo puede venir el jueves es ruido, y el ruido enseña a
 * ignorar los avisos.
 */
class WaitlistEntry extends Model
{
    protected $fillable = [
        'asset_id', 'user_id', 'wants_from', 'wants_until',
        'status', 'notified_at', 'note',
    ];

    protected function casts(): array
    {
        return [
            'wants_from'  => UtcDateTime::class,
            'wants_until' => UtcDateTime::class,
            'notified_at' => UtcDateTime::class,
        ];
    }

    public const ESTADOS = [
        'esperando' => 'Esperando',
        'avisado'   => 'Avisado',
        'atendido'  => 'Atendido',
        'vencido'   => 'Venció su ventana',
        'cancelado' => 'Cancelado',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** ¿Le sirve un hueco que va de $desde a $hasta? */
    public function leSirve(\Carbon\CarbonInterface $desde, \Carbon\CarbonInterface $hasta): bool
    {
        return $this->wants_from->lessThan($hasta) && $this->wants_until->greaterThan($desde);
    }
}
