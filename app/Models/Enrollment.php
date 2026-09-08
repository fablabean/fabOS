<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * La inscripción de una persona en una edición (§9).
 *
 * Al aprobar se emite un certificado con código público, verificable por
 * cualquiera igual que un certifab: sirve fuera del laboratorio, sin depender
 * de que la Universidad conteste un correo.
 */
class Enrollment extends Model
{
    protected $fillable = [
        'course_edition_id', 'user_id', 'status', 'grade', 'feedback',
        'certificate_code', 'completed_at', 'enrolled_at',
        'theory_score', 'theory_passed_at', 'theory_attempts',
        'practical_passed_at', 'practical_by', 'practical_notes',
    ];

    protected function casts(): array
    {
        return [
            'grade'        => 'decimal:2',
            'completed_at' => UtcDateTime::class,
            'enrolled_at'  => UtcDateTime::class,
            'theory_passed_at'    => UtcDateTime::class,
            'practical_passed_at' => UtcDateTime::class,
        ];
    }

    /** Quien firmo la practica: se ve delante de la maquina, no en una pantalla. */
    public function practicalBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'practical_by');
    }

    public function teoriaAprobada(): bool
    {
        return $this->theory_passed_at !== null;
    }

    /** Si el examen ya no estorba: no hay, o esta aprobado. */
    public function teoriaLista(): bool
    {
        return ! ($this->edition?->course?->tieneExamen()) || $this->teoriaAprobada();
    }

    /** Las reservas de prueba practica que vienen de esta inscripcion (§9). */
    public function practicas(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Reservation::class)->where('mode', 'practica');
    }

    /** La practica que esta en pie, si la hay: agendada y por venir. */
    public function practicaAgendada(): ?Reservation
    {
        return $this->practicas()
            ->whereIn('status', Reservation::BLOQUEANTES)
            ->where('ends_at', '>=', now())
            ->orderBy('starts_at')
            ->with('reservable')
            ->first();
    }

    /**
     * Quien tiene asignada la practica: el evaluador de la que esta en pie,
     * o de la ultima que hubo. Nulo si nunca se agendo una.
     */
    public function evaluadorAsignado(): ?User
    {
        $practica = $this->practicaAgendada()
            ?? $this->practicas()->whereNotIn('status', ['cancelada', 'rechazada'])->orderByDesc('starts_at')->with('reservable')->first();

        $evaluador = $practica?->reservable;

        return $evaluador instanceof User ? $evaluador : null;
    }

    /**
     * Si esta persona puede firmar o reprobar la practica: quien la tiene
     * asignada. Sin nadie asignado —una practica que se vio sin agendar—,
     * un administrador o superadmin.
     */
    public function puedeEvaluarLaPractica(?User $quien): bool
    {
        if (! $quien) {
            return false;
        }

        $asignado = $this->evaluadorAsignado();

        if ($asignado) {
            return $asignado->id === $quien->id;
        }

        return $quien->hasAnyRole([User::ROL_ADMINISTRADOR, User::ROL_SUPERADMIN]);
    }

    /**
     * Si ya puede pedir hora para la practica: sigue inscrita, el curso la
     * exige, la teoria esta lista, no esta firmada y no hay otra en pie.
     */
    public function puedeAgendarPractica(): bool
    {
        return $this->status === 'inscrito'
            && (bool) $this->edition?->course?->requires_practical
            && $this->teoriaLista()
            && ! $this->practicaAprobada()
            && $this->practicaAgendada() === null;
    }

    public function practicaAprobada(): bool
    {
        return $this->practical_passed_at !== null;
    }

    /**
     * Que falta para el certifab, dicho en una frase.
     *
     * Nulo si no falta nada. Decirlo importa: quien aprobo el examen y no
     * recibe el certifab no tiene forma de saber que espera una practica.
     */
    public function queFaltaParaAprobar(): ?string
    {
        $curso = $this->edition?->course;

        if ($curso?->tieneExamen() && ! $this->teoriaAprobada()) {
            return 'Falta aprobar el examen teórico.';
        }

        if ($curso?->requires_practical && ! $this->practicaAprobada()) {
            return 'Falta la evaluación presencial, delante de la máquina.';
        }

        return null;
    }

    public const ESTADOS = [
        'inscrito'  => 'Inscrito',
        'aprobado'  => 'Aprobado',
        'reprobado' => 'No aprobado',
        'retirado'  => 'Retirado',
    ];

    /** El código se genera al aprobar, no antes: certifica algo que ya pasó. */
    public static function nuevoCodigo(): string
    {
        return 'C' . Str::upper(Str::random(9));
    }

    public function edition(): BelongsTo
    {
        return $this->belongsTo(CourseEdition::class, 'course_edition_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): ?Course
    {
        return $this->edition?->course;
    }

    public function aprobada(): bool
    {
        return $this->status === 'aprobado';
    }
}
