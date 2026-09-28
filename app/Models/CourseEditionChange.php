<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un cambio en una edición, con su motivo y a cuántos se les avisó (§9). */
class CourseEditionChange extends Model
{
    protected $fillable = [
        'course_edition_id', 'user_id', 'kind', 'cause', 'reason', 'before', 'after', 'notified',
    ];

    protected function casts(): array
    {
        return [
            'before'   => 'array',
            'after'    => 'array',
            'notified' => 'integer',
        ];
    }

    public const TIPOS = [
        'publicada'              => 'Publicada',
        'inscripciones_cerradas' => 'Inscripciones cerradas',
        'reabierta'              => 'Inscripciones reabiertas',
        'reprogramada'           => 'Reprogramada',
        'cancelada'              => 'Cancelada',
        'novedad'                => 'Novedad avisada',
        'cupo_asignado'          => 'Cupo asignado de la lista de espera',
        'encuesta'               => 'Encuesta enviada',
        'duplicada'              => 'Creada al duplicar otra',
    ];

    /** Por qué, cuando no es una decisión sino algo que pasó. */
    public const CAUSAS = [
        'planeacion'     => 'Ajuste de planeación',
        'sismo'          => 'Sismo o emergencia',
        'cierre'         => 'Cierre de instalaciones',
        'falla_tecnica'  => 'Falla técnica',
        'espacio'        => 'El espacio no está disponible',
        'instructor'     => 'Indisponibilidad de quien dicta',
        'ultimo_momento' => 'Cambio de último momento',
        'otro'           => 'Otro',
    ];

    public function edition(): BelongsTo
    {
        return $this->belongsTo(CourseEdition::class, 'course_edition_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tipoLegible(): string
    {
        return self::TIPOS[$this->kind] ?? $this->kind;
    }

    public function causaLegible(): ?string
    {
        return $this->cause ? (self::CAUSAS[$this->cause] ?? $this->cause) : null;
    }
}
