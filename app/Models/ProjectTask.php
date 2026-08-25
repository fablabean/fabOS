<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una tarea del proyecto (§11).
 *
 * La misma fila alimenta el Kanban —por su estado— y el Gantt —por sus fechas—.
 * Son dos formas de mirar lo mismo: separarlas en dos tablas garantizaría que
 * tarde o temprano cuenten cosas distintas.
 */
class ProjectTask extends Model
{
    protected $fillable = [
        'project_id', 'assigned_to', 'title', 'description', 'status',
        'starts_on', 'due_on', 'is_milestone', 'progress', 'position', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_on'    => 'date',
            'due_on'       => 'date',
            'is_milestone' => 'boolean',
            'completed_at' => UtcDateTime::class,
        ];
    }

    /**
     * Dejar el avance en blanco es decir «todavia nada», no un error. La
     * columna es NOT NULL con default 0, y un NULL explicito se salta ese
     * default: sin esto, borrar el campo revienta el guardado.
     */
    protected static function booted(): void
    {
        static::saving(function (self $tarea) {
            $tarea->progress ??= 0;
            $tarea->position ??= 0;
        });
    }

    /** Las columnas del tablero, en orden de lectura. */
    public const ESTADOS = [
        'por_hacer' => 'Por hacer',
        'en_curso'  => 'En curso',
        'bloqueada' => 'Bloqueada',
        'hecha'     => 'Hecha',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** Vencida es la que ya pasó su fecha y sigue sin hacerse. */
    public function estaVencida(): bool
    {
        return $this->due_on !== null
            && $this->status !== 'hecha'
            && $this->due_on->endOfDay()->isPast();
    }

    /** Días que ocupa en el Gantt. Un hito ocupa uno. */
    public function dias(): int
    {
        if (! $this->starts_on || ! $this->due_on) {
            return 1;
        }

        return max(1, (int) $this->starts_on->diffInDays($this->due_on) + 1);
    }
}
