<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un candidato de un lote, todavía no un proyecto (§11).
 *
 * Vive aparte a propósito: darle código de proyecto a algo que probablemente no
 * se acepte ensucia el único sitio donde se mira si el laboratorio entrega. Se
 * convierte en proyecto **cuando se acepta**, y ni un minuto antes.
 */
class Candidate extends Model
{
    protected $fillable = [
        'batch_id', 'name', 'organization',
        'contact_name', 'contact_email', 'contact_phone', 'description',
        'status', 'score', 'evaluation_note', 'evaluated_at', 'evaluated_by',
        'project_id', 'position',
    ];

    protected function casts(): array
    {
        return ['evaluated_at' => UtcDateTime::class];
    }

    public const ESTADOS = [
        'pendiente'  => 'Sin evaluar',
        'aceptado'   => 'Aceptado',
        'descartado' => 'Descartado',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CandidateBatch::class, 'batch_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function evaluatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluated_by');
    }

    public function estaEvaluado(): bool
    {
        return $this->status !== 'pendiente';
    }

    public function yaEsProyecto(): bool
    {
        return $this->project_id !== null;
    }

    /** En qué va, dicho de una vez. */
    public function enQueVa(): string
    {
        if ($this->yaEsProyecto()) {
            return 'Ya es ' . ($this->project?->code ?? 'un proyecto');
        }

        return match ($this->status) {
            'aceptado'   => 'Aceptado, falta convertirlo',
            'descartado' => 'Descartado',
            default      => 'Sin evaluar',
        };
    }
}
