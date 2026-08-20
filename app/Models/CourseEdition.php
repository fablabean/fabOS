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
    ];

    protected function casts(): array
    {
        return [
            // Fechas de calendario, NO instantes: convertirlas de zona movería
            // el inicio de un curso al día anterior.
            'starts_on' => 'date',
            'ends_on'   => 'date',
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
}
