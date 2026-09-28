<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Una sesión de una edición: un día de clase, la jornada de un evento (§9).
 *
 * Cada una tiene su QR. Uno por edición no serviría: en un curso de cuatro
 * sábados, quien vino al primero habría «asistido» a los cuatro.
 */
class CourseSession extends Model
{
    protected $fillable = ['course_edition_id', 'title', 'starts_at', 'ends_at', 'token'];

    protected function casts(): array
    {
        return [
            'starts_at' => UtcDateTime::class,
            'ends_at'   => UtcDateTime::class,
        ];
    }

    /** Cuánto antes y después de la sesión el QR registra asistencia. */
    public const MARGEN_MINUTOS = 60;

    protected static function booted(): void
    {
        static::creating(function (self $sesion) {
            $sesion->token ??= Str::random(32);
        });
    }

    public function edition(): BelongsTo
    {
        return $this->belongsTo(CourseEdition::class, 'course_edition_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(SessionAttendance::class);
    }

    /** El fin, o cuatro horas después del inicio si nadie lo dijo. */
    public function terminaA(): Carbon
    {
        return $this->ends_at ?? $this->starts_at->copy()->addHours(4);
    }

    /** Si el QR registra ahora: desde una hora antes hasta una hora después. */
    public function qrAbierto(?Carbon $ahora = null): bool
    {
        $ahora ??= now();

        return $ahora->between(
            $this->starts_at->copy()->subMinutes(self::MARGEN_MINUTOS),
            $this->terminaA()->copy()->addMinutes(self::MARGEN_MINUTOS),
        );
    }

    public function url(): string
    {
        return route('asistencia', $this->token);
    }

    /** «Sábado 4 de octubre · 09:00 a 12:00», en hora del laboratorio. */
    public function nombre(): string
    {
        $tz = config('fabos.lab.timezone');
        $inicio = $this->starts_at->copy()->timezone($tz)->locale('es');

        return ($this->title ? $this->title . ' · ' : '')
            . ucfirst($inicio->translatedFormat('l j \d\e F'))
            . ' · ' . $inicio->format('H:i')
            . ($this->ends_at ? ' a ' . $this->ends_at->copy()->timezone($tz)->format('H:i') : '');
    }
}
