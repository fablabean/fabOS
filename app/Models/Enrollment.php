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
    ];

    protected function casts(): array
    {
        return [
            'grade'        => 'decimal:2',
            'completed_at' => UtcDateTime::class,
            'enrolled_at'  => UtcDateTime::class,
        ];
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
