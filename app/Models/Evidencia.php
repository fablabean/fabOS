<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * La prueba de que algo se hizo, y con qué (§11).
 *
 * Cuelga de lo que haga falta demostrar: una **tarea** —«se hizo» es una
 * afirmación, una foto es una comprobación—, un **entregable** —el archivo
 * definitivo que se entregó— o una **producción** —el .stl y el .gcode que
 * salieron de la máquina, con proyecto detrás o siendo la pieza de un
 * estudiante—.
 *
 * Subida o enlazada: las dos formas son reales. Un video de dos minutos ya vive
 * en algún sitio, y obligar a subirlo haría que nadie documente.
 *
 * Los archivos van al **disco privado**. Son el trabajo de alguien: en el disco
 * público quedarían en una URL adivinable que cualquiera puede pedir sin haber
 * iniciado sesión. Se sirven por una ruta que comprueba quién pide.
 */
class Evidencia extends Model
{
    protected $table = 'evidencias';

    protected $fillable = [
        'evidenciable_type', 'evidenciable_id',
        'kind', 'file_path', 'url', 'caption', 'original_name', 'uploaded_by',
    ];

    public const TIPOS = [
        'foto'    => 'Foto',
        'video'   => 'Video',
        'archivo' => 'Archivo',
        'enlace'  => 'Enlace',
    ];

    /** Los que se guardan como archivo subido y no como enlace. */
    public const SE_SUBEN = ['foto', 'archivo'];

    public function evidenciable(): MorphTo
    {
        return $this->morphTo();
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
            ?: $this->original_name
            ?: (self::TIPOS[$this->kind] ?? $this->kind);
    }
}
