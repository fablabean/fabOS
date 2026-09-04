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
        'contact_name', 'contact_email', 'contact_phone', 'description', 'extra',
        'status', 'score', 'evaluation_note', 'fablab_note', 'evaluated_at', 'evaluated_by',
        'project_id', 'position',
    ];

    protected function casts(): array
    {
        return ['evaluated_at' => UtcDateTime::class, 'extra' => 'array'];
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

    /**
     * Lo que trajo la lista y no cabe en las columnas fijas, como pares
     * «columna: valor», sin los vacíos. Es lo que se lee al evaluar.
     *
     * @return array<string,string>
     */
    public function extras(): array
    {
        return collect($this->extra ?? [])
            ->filter(fn ($v) => filled($v))
            ->map(fn ($v) => (string) $v)
            ->all();
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
