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
