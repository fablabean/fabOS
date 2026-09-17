<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alguien que dijo «si esa cohorte abre, voy» (§9).
 *
 * No es una inscripción: no ocupa cupo y no tiene por qué tener cuenta. Es lo
 * que permite decidir si la cohorte se abre con un número delante y no con la
 * memoria de quién preguntó por el pasillo. Se convierte en inscripción cuando
 * la cohorte abre y el equipo lo inscribe, y ni un minuto antes.
 */
class Preenrollment extends Model
{
    protected $fillable = [
        'course_edition_id', 'name', 'email', 'phone', 'city', 'occupation', 'institution',
        'motivation', 'portfolio_url', 'funding', 'source', 'consent_at',
        'status', 'confirmed_at', 'user_id', 'enrollment_id', 'notes',
    ];

    protected $attributes = ['status' => 'preinscrito'];

    public const ESTADOS = [
        'preinscrito' => 'Preinscrito',
        'confirmado'  => 'Confirmó que va',
        'inscrito'    => 'Ya inscrito',
        'desistio'    => 'Desistió',
    ];

    /** Los que todavía cuentan para saber si la cohorte se abre. */
    public const VIVOS = ['preinscrito', 'confirmado', 'inscrito'];

    public const ORIGENES = [
        'web'    => 'Se preinscribió por el sitio',
        'equipo' => 'Lo anotó el equipo',
    ];

    /**
     * Cómo piensa pagarlo. Es lo que separa el interés de la viabilidad: diez
     * personas esperando una beca que no existe no son diez estudiantes.
     */
    public const FINANCIACION = [
        'propio'      => 'Recursos propios',
        'empresa'     => 'Lo paga mi empresa',
        'institucion' => 'Lo paga mi universidad o institución',
        'beca'        => 'Necesitaría una beca o apoyo',
        'no_se'       => 'Todavía no lo sé',
    ];

    protected function casts(): array
    {
        return [
            'consent_at'   => UtcDateTime::class,
            'confirmed_at' => UtcDateTime::class,
        ];
    }

    public function edition(): BelongsTo
    {
        return $this->belongsTo(CourseEdition::class, 'course_edition_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    // ------------------------------------------------------------- lecturas

    /** De la casa o de fuera: se deriva del correo, no se marca (§5). */
    public function esInterno(): bool
    {
        return User::correoInstitucional($this->email);
    }

    public function sigueVivo(): bool
    {
        return in_array($this->status, self::VIVOS, true);
    }

    public function yaInscrito(): bool
    {
        return $this->status === 'inscrito';
    }

    public function estadoLegible(): string
    {
        return self::ESTADOS[$this->status] ?? $this->status;
    }

    public function financiacionLegible(): ?string
    {
        return $this->funding ? (self::FINANCIACION[$this->funding] ?? $this->funding) : null;
    }

    /** Quién es, en una línea: «Diseñadora industrial · Acme · Medellín». */
    public function quienEs(): ?string
    {
        $partes = array_filter([$this->occupation, $this->institution, $this->city]);

        return $partes ? implode(' · ', $partes) : null;
    }

    public function scopeVivos(Builder $query): Builder
    {
        return $query->whereIn('status', self::VIVOS);
    }
}
