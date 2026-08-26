<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un compromiso concreto del proyecto (§11).
 *
 * «Qué se compromete a entregar» era un párrafo, y un párrafo no se puede
 * marcar como cumplido. Como lista, cada compromiso tiene estado propio y se
 * puede llevar al tablero como **hito**: un entregable es exactamente eso, un
 * compromiso con fecha.
 *
 * La tarea es opcional a propósito. Un entregable existe desde que se promete,
 * mucho antes de que alguien planifique cómo hacerlo, y algunos se cumplen sin
 * pasar nunca por el tablero.
 */
class ProjectDeliverable extends Model
{
    protected $fillable = [
        'project_id', 'title', 'detail', 'due_on', 'position', 'task_id', 'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'due_on'       => 'date',
            'delivered_at' => UtcDateTime::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $entregable) {
            $entregable->position ??= 0;
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'task_id');
    }

    /**
     * Cumplido es cumplido, se haya marcado a mano o lo diga su tarea. Si solo
     * mirara la marca propia, cerrar la tarea en el tablero dejaría el
     * entregable pendiente para siempre y las dos vistas se contradirían.
     */
    public function estaEntregado(): bool
    {
        return $this->delivered_at !== null || $this->task?->status === 'hecha';
    }

    /** En qué anda, dicho para quien lee la ficha. */
    public function estado(): string
    {
        if ($this->estaEntregado()) {
            return 'entregado';
        }

        if (! $this->task) {
            return 'sin tarea';
        }

        return ProjectTask::ESTADOS[$this->task->status] ?? $this->task->status;
    }
}
