<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Orden de trabajo: preventiva programada o correctiva por una falla (§8). */
class WorkOrder extends Model
{
    protected $fillable = [
        'asset_id', 'maintenance_plan_id', 'kind', 'status', 'priority',
        'reported_issue', 'reported_by', 'assigned_to',
        'checklist_snapshot', 'checklist_answers',
        'diagnosis', 'work_done', 'photos', 'cost',
        'stops_equipment', 'down_since', 'up_since', 'due_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'checklist_snapshot' => 'array',
            'checklist_answers'  => 'array',
            'photos'             => 'array',
            'stops_equipment'    => 'boolean',
            'cost'               => 'decimal:2',
            'down_since'         => UtcDateTime::class,
            'up_since'           => UtcDateTime::class,
            'due_at'             => UtcDateTime::class,
            'closed_at'          => UtcDateTime::class,
        ];
    }

    public const TIPOS = ['preventivo' => 'Preventivo', 'correctivo' => 'Correctivo'];

    public const ESTADOS = [
        'abierta'    => 'Abierta',
        'en_proceso' => 'En proceso',
        'cerrada'    => 'Cerrada',
        'cancelada'  => 'Cancelada',
    ];

    public const PRIORIDADES = [
        'baja' => 'Baja', 'normal' => 'Normal', 'alta' => 'Alta', 'critica' => 'Crítica',
    ];

    /** Estados en los que la orden sigue viva. */
    public const ABIERTAS = ['abierta', 'en_proceso'];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MaintenancePlan::class, 'maintenance_plan_id');
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** Minutos que el equipo estuvo detenido. Alimenta el MTTR. */
    public function minutosDeParo(): ?int
    {
        if (! $this->down_since) {
            return null;
        }

        return (int) $this->down_since->diffInMinutes($this->up_since ?? now());
    }

    public function estaVencida(): bool
    {
        return $this->due_at !== null
            && $this->due_at->isPast()
            && in_array($this->status, self::ABIERTAS, true);
    }
}
