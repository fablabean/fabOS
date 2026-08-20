<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** El documento que sostiene una etapa del proyecto (§11). */
class ProjectDocument extends Model
{
    protected $fillable = [
        'project_id', 'kind', 'title', 'file_path', 'url',
        'uploaded_by', 'signed_on', 'notes',
    ];

    protected function casts(): array
    {
        return ['signed_on' => 'date'];
    }

    public const TIPOS = [
        'propuesta' => 'Propuesta',
        'contrato'  => 'Contrato u orden de servicio',
        'brief'     => 'Brief',
        'acta'      => 'Acta de hito',
        'informe'   => 'Informe de cierre',
        'otro'      => 'Otro',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Dónde está de verdad: archivo subido o enlace externo. */
    public function enlace(): ?string
    {
        return $this->url ?: ($this->file_path ? asset('storage/' . $this->file_path) : null);
    }
}
