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
        'diagnosis', 'resultado_revision', 'esquema_revision', 'work_done',
        'piezas_reemplazadas', 'estado_post', 'pruebas_realizadas', 'recomendaciones', 'photos', 'reference_videos', 'cost',
        'stops_equipment', 'down_since', 'up_since', 'due_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'checklist_snapshot' => 'array',
            'checklist_answers'  => 'array',
            'photos'             => 'array',
            'piezas_reemplazadas' => 'array',
            'reference_videos'   => 'array',
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

    /**
     * El diagnóstico de la revisión rutinaria (INV-118 §6.3): lo que dice el
     * «Informe de Diagnóstico de Revisión Rutinaria» de una orden preventiva.
     */
    public const RESULTADOS = [
        'todo_ok'     => 'Todo OK',
        'menores'     => 'Problemas menores',
        'importantes' => 'Problemas importantes',
    ];

    /**
     * Toda orden cerrada se descarga como informe: la preventiva como el de
     * Diagnóstico de Revisión Rutinaria; la correctiva como el Informe de
     * Mantenimiento (INV-118 §6.4).
     */
    public function tieneInforme(): bool
    {
        return $this->status === 'cerrada';
    }

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
