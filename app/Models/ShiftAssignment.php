<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Jornada puntual fuera del patrón semanal: sábados, eventos (§5). */
class ShiftAssignment extends Model
{
    protected $fillable = [
        'user_id', 'starts_at', 'ends_at', 'reason',
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

    public function minutos(): int
    {
        return (int) $this->starts_at->diffInMinutes($this->ends_at);
    }
}
