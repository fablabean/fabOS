<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La prueba de que una tarea se hizo (§11).
 *
 * «Se hizo» es una afirmación; una foto es una comprobación. Dentro de dos
 * años, cuando nadie recuerde el proyecto, la diferencia entre las dos es todo
 * lo que queda —y es también lo que se le enseña a quien encargó el trabajo—.
 *
 * Subida o enlazada, las dos formas son reales: un video de dos minutos ya vive
 * en algún sitio, y obligar a subirlo haría que nadie documente.
 *
 * Los archivos van al **disco privado**. Son fotos del trabajo de un cliente:
 * en el disco público quedarían en una URL que cualquiera puede pedir sin haber
 * iniciado sesión. Se sirven por una ruta que comprueba quién pide.
 */
class ProjectTaskEvidence extends Model
{
    protected $table = 'project_task_evidence';

    protected $fillable = ['task_id', 'kind', 'file_path', 'url', 'caption', 'uploaded_by'];

    public const TIPOS = [
        'foto'   => 'Foto',
        'video'  => 'Video',
        'enlace' => 'Enlace',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'task_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function esImagen(): bool
    {
        return $this->kind === 'foto' && filled($this->file_path);
    }

    /** Dónde está de verdad: la ruta con sesión, o el enlace externo. */
    public function enlace(): ?string
    {
        if (filled($this->file_path)) {
            return route('proyectos.evidencia', $this);
        }

        return $this->url ?: null;
    }

    public function comoSeLlama(): string
    {
        return $this->caption
            ?: (self::TIPOS[$this->kind] ?? $this->kind) . ' de ' . ($this->task?->title ?? 'la tarea');
    }
}
