<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Un curso de la escalera de formación (§9).
 *
 * Los niveles no son adorno: bit, byte, kilo, mega, giga y tera marcan cuánta
 * autonomía puede llegar a tener alguien. tera es Fab Academy.
 */
class Course extends Model
{
    protected $fillable = [
        'slug', 'name', 'area_id', 'level', 'summary', 'description',
        'requirements', 'hours', 'passing_score', 'requires_practical',
        'photo_path', 'price_minor', 'is_active', 'is_public',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'requires_practical' => 'boolean',
            'passing_score' => 'integer',
        ];
    }

    public const NIVELES = [
        'bit'  => 'bit · primer contacto',
        'byte' => 'byte · uso básico',
        'kilo' => 'kilo · uso autónomo',
        'mega' => 'mega · proyectos propios',
        'giga' => 'giga · acompañar a otros',
        'tera' => 'tera · Fab Academy',
    ];

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function editions(): HasMany
    {
        return $this->hasMany(CourseEdition::class);
    }

    /** La teoria, en orden: son pantallas cortas, no un manual. */
    public function lessons(): HasMany
    {
        return $this->hasMany(CourseLesson::class)->orderBy('position')->orderBy('id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(CourseQuestion::class)->orderBy('position')->orderBy('id');
    }

    /** Si tiene examen que corregir. Sin preguntas no hay nada que aprobar. */
    public function tieneExamen(): bool
    {
        return $this->questions()->exists();
    }

    /** Qué habilita aprobarlo. Sin esto un curso es solo una charla. */
    public function riskFamilies(): BelongsToMany
    {
        return $this->belongsToMany(RiskFamily::class, 'course_risk_family');
    }

    public function precio(): float
    {
        return $this->price_minor / config('fabos.currency.minor_units');
    }

    /** Ediciones a las que todavía se puede entrar. */
    public function edicionesAbiertas(): HasMany
    {
        return $this->editions()->where('status', 'abierta')->orderBy('starts_on');
    }

    /** Toda la gente que pasó por el curso, en cualquiera de sus ediciones. */
    public function inscripciones(): HasManyThrough
    {
        return $this->hasManyThrough(Enrollment::class, CourseEdition::class);
    }

    /**
     * Si se puede borrar: solo cuando nadie pasó por el.
     *
     * Una inscripcion es una persona con su nota, sus intentos de examen y
     * lo que alguien firmo delante de la maquina. Borrar el curso se lo
     * llevaria todo, y eso no se borra: se apaga el curso y se queda como
     * registro. Sus ediciones vacias, en cambio, se van con el.
     */
    public function sePuedeBorrar(): bool
    {
        return ! $this->inscripciones()->exists();
    }

    /** Por que no se borra, dicho para quien lo intenta. Null si se puede. */
    public function porQueNoSeBorra(): ?string
    {
        if ($this->sePuedeBorrar()) {
            return null;
        }

        $n = $this->inscripciones()->count();

        return $n === 1
            ? 'Una persona pasó por este curso: no se borra, se apaga.'
            : "{$n} personas pasaron por este curso: no se borra, se apaga.";
    }
}
