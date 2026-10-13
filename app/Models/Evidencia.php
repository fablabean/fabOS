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
        'evidenciable_type', 'evidenciable_id', 'project_comment_id',
        'kind', 'file_path', 'url', 'caption', 'original_name', 'uploaded_by', 'project_id',
    ];

    /**
     * El nombre de cada archivo lleva su proyecto delante.
     *
     * `PRY-2026-0159_20261005_03_pieza-1.stl`: código, fecha en que se subió,
     * consecutivo del día dentro de ese proyecto, y el nombre con que llegó.
     * Bajados a una carpeta, veinte «modelo.stl» de veinte personas no se
     * distinguen; así se ordenan solos y se sabe de quién es cada uno. El
     * nombre original se conserva al final porque «carcasa-v3-final» dice
     * algo que un número no dice.
     *
     * Se hace aquí, al guardar, y no en cada sitio que sube: son cinco
     * puertas —la solicitud, las respuestas, el dibujo, el panel, las
     * propuestas— y la sexta también lo haría mal.
     */
    protected static function booted(): void
    {
        static::saving(function (self $e) {
            $e->project_id ??= $e->proyectoDeDondeCuelga();

            $archivoNuevo = filled($e->file_path) && (! $e->exists || $e->isDirty('file_path'));

            if ($archivoNuevo && $e->project_id && ($proyecto = Project::find($e->project_id))) {
                $fecha = ($e->created_at ?? now())->copy()->timezone(config('fabos.lab.timezone'));

                $e->original_name = self::nombreOrdenado(
                    $proyecto->code,
                    $fecha,
                    self::siguienteConsecutivo($proyecto->id, $fecha, $e->getKey()),
                    $e->original_name,
                    $e->file_path,
                    $e->kind,
                );
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** El proyecto, venga la evidencia de donde venga: de él, de una tarea, de un entregable. */
    private function proyectoDeDondeCuelga(): ?int
    {
        if (! $this->evidenciable_type || ! $this->evidenciable_id) {
            return null;
        }

        if ($this->evidenciable_type === Project::class) {
            return (int) $this->evidenciable_id;
        }

        if (in_array($this->evidenciable_type, [
            ProjectDeliverable::class, ProjectTask::class, ProjectProposal::class, Reservation::class,
        ], true)) {
            return $this->evidenciable_type::query()->whereKey($this->evidenciable_id)->value('project_id');
        }

        return null;
    }

    /** El que sigue ese día en ese proyecto, contando lo que ya hay. */
    public static function siguienteConsecutivo(int $proyectoId, \Carbon\CarbonInterface $fecha, mixed $menosEste = null): int
    {
        $dia = $fecha->copy()->timezone(config('fabos.lab.timezone'));

        return static::query()
            ->where('project_id', $proyectoId)
            ->whereNotNull('file_path')
            ->when($menosEste, fn ($q) => $q->whereKeyNot($menosEste))
            ->whereBetween('created_at', [$dia->copy()->startOfDay()->utc(), $dia->copy()->endOfDay()->utc()])
            ->count() + 1;
    }

    /** Cómo empieza un nombre ya ordenado: para no ponerle el prefijo dos veces. */
    public const PREFIJO = '/^[A-Z]{2,5}-\d{4}-\d{3,6}_\d{8}_\d{2,}_/';

    public static function nombreOrdenado(
        string $codigo,
        \Carbon\CarbonInterface $fecha,
        int $consecutivo,
        ?string $original,
        ?string $ruta,
        ?string $tipo = null,
    ): string {
        $original = preg_replace(self::PREFIJO, '', trim((string) $original));

        // La extensión con que llegó; si no trae nombre, la del archivo guardado.
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION) ?: pathinfo((string) $ruta, PATHINFO_EXTENSION));
        $extension = preg_replace('/[^a-z0-9]/', '', $extension);

        $base = \Illuminate\Support\Str::slug(\Illuminate\Support\Str::limit(pathinfo($original, PATHINFO_FILENAME), 60, ''))
            ?: ($tipo === 'foto' ? 'foto' : 'archivo');

        return $codigo . '_' . $fecha->format('Ymd') . '_' . str_pad((string) $consecutivo, 2, '0', STR_PAD_LEFT)
            . '_' . $base . ($extension !== '' ? '.' . $extension : '');
    }

    /** La respuesta con la que llego, si llego con una. */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(ProjectComment::class, 'project_comment_id');
    }

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
