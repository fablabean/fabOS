<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una cohorte concreta de un curso (§9).
 *
 * Es lo que se inscribe y lo que se cierra. El curso dice qué se enseña; la
 * edición, cuándo, con quién y para cuántos.
 */
class CourseEdition extends Model
{
    protected $fillable = [
        'course_id', 'code', 'instructor_id', 'space_id',
        'starts_on', 'ends_on', 'schedule_note', 'capacity', 'status', 'notes',
        'minimum_to_open', 'preenroll_until', 'price_note',
    ];

    protected function casts(): array
    {
        return [
            // Fechas de calendario, NO instantes: convertirlas de zona movería
            // el inicio de un curso al día anterior.
            'starts_on' => 'date',
            'ends_on'   => 'date',
            'preenroll_until' => 'date',
        ];
    }

    public const ESTADOS = [
        'planeada'  => 'Planeada',
        'abierta'   => 'Inscripciones abiertas',
        'en_curso'  => 'En curso',
        'cerrada'   => 'Cerrada',
        'cancelada' => 'Cancelada',
    ];

    /** Estados en los que la edición todavía cuenta para algo. */
    public const VIVAS = ['planeada', 'abierta', 'en_curso'];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /** Cuenta solo a quien sigue dentro: quien se retiró libera su cupo. */
    public function inscritos(): int
    {
        return $this->enrollments()->whereNot('status', 'retirado')->count();
    }

    public function cuposLibres(): int
    {
        return max(0, $this->capacity - $this->inscritos());
    }

    public function admiteInscripciones(): bool
    {
        return $this->status === 'abierta' && $this->cuposLibres() > 0;
    }

    // ------------------------------------------------------ preinscripción

    public function preenrollments(): HasMany
    {
        return $this->hasMany(Preenrollment::class);
    }

    /** Los que cuentan: quien desistió ya no suma para abrir. */
    public function preinscritos(): int
    {
        return $this->preenrollments()->vivos()->count();
    }

    /** Los que ya dijeron que sí van. Es el número con el que se decide. */
    public function confirmados(): int
    {
        return $this->preenrollments()->whereIn('status', ['confirmado', 'inscrito'])->count();
    }

    /**
     * Cuántos faltan para abrir. Nulo si nadie dijo cuántos hacen falta: la
     * página pública no promete un umbral que no existe.
     */
    public function faltanParaAbrir(): ?int
    {
        return $this->minimum_to_open === null
            ? null
            : max(0, $this->minimum_to_open - $this->preinscritos());
    }

    /**
     * Si alguien puede preinscribirse ahora mismo desde el sitio.
     *
     * Solo una cohorte **planeada** de un curso que entra por preinscripción:
     * cuando ya abrió, lo que toca es inscribirse de verdad, y ofrecer las dos
     * puertas a la vez deja gente creyendo que tiene cupo sin tenerlo.
     */
    public function admitePreinscripciones(): bool
    {
        return $this->porQueNoAdmitePreinscripciones() === null;
    }

    /** Por qué no se puede, dicho a quien lo intenta. Null si se puede. */
    public function porQueNoAdmitePreinscripciones(): ?string
    {
        if (! $this->course?->by_preenrollment) {
            return 'A este curso no se entra por preinscripción.';
        }

        if ($this->status !== 'planeada') {
            return $this->status === 'abierta'
                ? 'Esta cohorte ya abrió inscripciones: la preinscripción terminó.'
                : 'Esta cohorte ya no recibe preinscripciones.';
        }

        $hoy = now(config('fabos.lab.timezone'))->startOfDay();

        if ($this->preenroll_until && $hoy->gt($this->preenroll_until)) {
            return 'Las preinscripciones se recibieron hasta el ' . $this->preenroll_until->format('d/m/Y') . '.';
        }

        return null;
    }
}
