<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Jornada puntual fuera del patrón semanal: sábados, eventos (§5). */
class ShiftAssignment extends Model
{
    protected $fillable = [
        'user_id', 'starts_at', 'ends_at', 'reason', 'project_id', 'reservation_id',
        'counts_as_overtime', 'assigned_by', 'accepted_at', 'conflict_note',
        'reporte_id', 'actividades', 'compensa_el',
    ];

    protected function casts(): array
    {
        return [
            'starts_at'          => UtcDateTime::class,
            'ends_at'            => UtcDateTime::class,
            'accepted_at'        => UtcDateTime::class,
            'counts_as_overtime' => 'boolean',
            'compensa_el'        => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /** El proyecto por el que se abrio, si es por uno (§11). */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * La reserva por la que se abrio, si es por una (§7): el sabado que
     * alguien pidio el laboratorio de VR y hay que abrirle.
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public const PROGRAMADA = 'programada';
    public const PENDIENTE  = 'pendiente';
    public const REPORTADA  = 'reportada';
    public const COMPENSADA = 'compensada';

    /** Como se le dice a cada estado en pantalla. */
    public const ESTADOS = [
        self::PROGRAMADA => 'Programada',
        self::PENDIENTE  => 'Pendiente de reporte',
        self::REPORTADA  => 'Reportada para nómina',
        self::COMPENSADA => 'Compensada',
    ];

    public const COLORES = [
        self::PROGRAMADA => 'info',
        self::PENDIENTE  => 'warning',
        self::REPORTADA  => 'success',
        self::COMPENSADA => 'gray',
    ];

    /**
     * Una jornada que ya fue en un reporte no se borra: es lo que se le pagó a
     * alguien. Si hay que corregirla, se anula el reporte primero.
     */
    protected static function booted(): void
    {
        static::deleting(fn (self $j) => $j->reporte_id === null);

        // Con contrato flexible no hay horas extras: lo de fuera de la jornada
        // se compensa con tiempo. Se impone aquí para que ningún camino —el
        // formulario, una solicitud aprobada— la deje como extra.
        static::saving(function (self $j) {
            if ($j->counts_as_overtime !== false && $j->user_id && User::whereKey($j->user_id)->value('contrato_flexible')) {
                $j->counts_as_overtime = false;
            }
        });
    }

    /** El reporte de nómina que la llevó, si ya fue en uno. */
    public function reporte(): BelongsTo
    {
        return $this->belongsTo(ReporteDeExtras::class, 'reporte_id');
    }

    /**
     * En qué va: compensada (se paga con tiempo, no va a nómina), reportada
     * (ya fue en un reporte), programada (todavía no termina) o pendiente de
     * reporte (ya se hizo y falta reportarla).
     */
    public function estado(?\Carbon\CarbonInterface $ahora = null): string
    {
        return match (true) {
            ! $this->counts_as_overtime          => self::COMPENSADA,
            $this->reporte_id !== null           => self::REPORTADA,
            $this->ends_at->gt($ahora ?? now())  => self::PROGRAMADA,
            default                              => self::PENDIENTE,
        };
    }

    /** Ya hecha, extra y sin reportar. */
    public function scopePendientesDeReporte(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        return $q->where('counts_as_overtime', true)->whereNull('reporte_id')->where('ends_at', '<=', now()->utc());
    }

    /**
     * Los minutos de esta jornada entre las 19:00 y las 6:00, que es lo que
     * el formato de nómina cuenta como nocturno.
     */
    public function minutosNocturnos(): int
    {
        return \App\Services\Staffing\FormatoAdf404::partir($this)['nocturnas'];
    }

    public function minutos(): int
    {
        return (int) $this->starts_at->diffInMinutes($this->ends_at);
    }
}
