<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una foto o un video del laboratorio (§21).
 *
 * Lo que pasa en un fablab se documenta con el teléfono o no se documenta. Si
 * hay que descargarlo, pasarlo a un computador y subirlo a una carpeta, no
 * ocurre; por eso esto se sube desde la cámara y en el mismo minuto.
 *
 * Se comparte con Comunicaciones de la Universidad, y de ahí la autorización:
 * compartir material del que no se tienen derechos es un problema de la
 * institución, no del archivo.
 */
class Contenido extends Model
{
    protected $table = 'contenidos';

    protected $fillable = [
        'user_id', 'project_id', 'area_id', 'kind',
        'file_path', 'original_name', 'mime', 'size_bytes',
        'title', 'description',
        'rights_accepted_at', 'rights_version',
        'withdrawn_at', 'withdrawn_reason',
        'recognized_at', 'recognized_minor', 'recognized_by',
    ];

    protected function casts(): array
    {
        return [
            'rights_accepted_at' => UtcDateTime::class,
            'withdrawn_at'       => UtcDateTime::class,
            'recognized_at'      => UtcDateTime::class,
            'recognized_minor'   => 'integer',
        ];
    }

    public const TIPOS = [
        'foto'  => 'Foto',
        'video' => 'Video',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    /** Quien decidió reconocer el aporte. Emitir moneda lleva firma. */
    public function reconocidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recognized_by');
    }

    public function estaReconocido(): bool
    {
        return $this->recognized_at !== null;
    }

    /**
     * Si este aporte puede reconocerse hoy.
     *
     * Un aporte retirado no se reconoce: se retira porque no se puede usar
     * -sale alguien que no quiere aparecer, se subió por error-, y pagar por
     * material que el laboratorio acaba de apartar seria contradecirse.
     */
    public function sePuedeReconocer(): bool
    {
        return ! $this->estaReconocido() && $this->estaDisponible();
    }

    /** Se retira, no se borra: el archivo sigue siendo del proyecto. */
    public function estaDisponible(): bool
    {
        return $this->withdrawn_at === null;
    }

    public function esVideo(): bool
    {
        return $this->kind === 'video';
    }

    /** Por una ruta que comprueba quién pide, nunca por /storage. */
    public function enlace(): string
    {
        return route('contenido.archivo', $this);
    }

    public function comoSeLlama(): string
    {
        return $this->title
            ?: $this->original_name
            ?: (self::TIPOS[$this->kind] ?? $this->kind);
    }

    public function peso(): ?string
    {
        if (! $this->size_bytes) {
            return null;
        }

        $megas = $this->size_bytes / 1_048_576;

        return $megas < 1
            ? number_format($this->size_bytes / 1024, 0, ',', '.') . ' KB'
            : number_format($megas, 1, ',', '.') . ' MB';
    }
}
