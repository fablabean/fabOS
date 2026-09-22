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
    ];

    protected function casts(): array
    {
        return [
            'starts_at'          => UtcDateTime::class,
            'ends_at'            => UtcDateTime::class,
            'accepted_at'        => UtcDateTime::class,
            'counts_as_overtime' => 'boolean',
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

    public function minutos(): int
    {
        return (int) $this->starts_at->diffInMinutes($this->ends_at);
    }
}
