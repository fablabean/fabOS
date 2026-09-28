<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Quién vino a una sesión, y cómo quedó anotado (§9). */
class SessionAttendance extends Model
{
    protected $fillable = [
        'course_session_id', 'enrollment_id', 'status', 'method', 'marked_by', 'marked_at', 'note',
    ];

    protected function casts(): array
    {
        return ['marked_at' => UtcDateTime::class];
    }

    public const ESTADOS = [
        'asistio'    => 'Asistió',
        'no_asistio' => 'No asistió',
    ];

    public const METODOS = [
        'qr'     => 'Con el QR',
        'manual' => 'A mano',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(CourseSession::class, 'course_session_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }
}
