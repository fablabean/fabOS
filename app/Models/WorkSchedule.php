<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Patrón semanal de jornada de una persona (§5). */
class WorkSchedule extends Model
{
    protected $fillable = [
        'user_id', 'weekday', 'starts_at', 'ends_at',
        'break_minutes', 'effective_from', 'effective_until',
    ];

    protected function casts(): array
    {
        return [
            'effective_from'  => 'date',
            'effective_until' => 'date',
        ];
    }

    public const DIAS = [
        1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves',
        5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Vigente en una fecha dada: el contrato puede haber cambiado. */
    public function scopeVigenteEn(Builder $q, \DateTimeInterface $fecha): Builder
    {
        return $q->whereDate('effective_from', '<=', $fecha)
            ->where(fn (Builder $s) => $s->whereNull('effective_until')
                ->orWhereDate('effective_until', '>=', $fecha));
    }

    /** Horas efectivas del día, descontando el descanso. */
    public function horasEfectivas(): float
    {
        [$hi, $mi] = array_map('intval', explode(':', $this->starts_at));
        [$hf, $mf] = array_map('intval', explode(':', $this->ends_at));

        $minutos = (($hf * 60 + $mf) - ($hi * 60 + $mi)) - $this->break_minutes;

        return round($minutos / 60, 2);
    }
}
