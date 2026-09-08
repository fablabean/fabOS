<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Patrón semanal de jornada de una persona (§5). */
class WorkSchedule extends Model
{
    protected $fillable = [
        'user_id', 'weekday', 'starts_at', 'ends_at',
        'break_minutes', 'break_starts_at', 'modalidad', 'effective_from', 'effective_until',
    ];

    /**
     * A que hora es el descanso, si se dijo.
     *
     * @return array{0:string,1:string}|null  inicio y fin, como «12:00» y «13:00»
     */
    public function descanso(): ?array
    {
        if (blank($this->break_starts_at) || (int) $this->break_minutes <= 0) {
            return null;
        }

        $inicio = \Illuminate\Support\Carbon::parse('2000-01-01 ' . $this->break_starts_at);
        $fin = $inicio->copy()->addMinutes((int) $this->break_minutes);

        return [$inicio->format('H:i'), $fin->format('H:i')];
    }

    /**
     * Si el descanso de esta jornada pisa el intervalo pedido, que se toma
     * como horas de pared del mismo dia. Tocarse por el borde no es pisarse:
     * un almuerzo hasta la una no impide una asesoria a la una.
     */
    public function descansoOcupa(\Carbon\CarbonInterface $desde, \Carbon\CarbonInterface $hasta): bool
    {
        $descanso = $this->descanso();

        if (! $descanso) {
            return false;
        }

        $tz = config('fabos.lab.timezone');
        $dia = $desde->copy()->setTimezone($tz)->startOfDay();
        $inicio = $dia->copy()->setTimeFromTimeString($descanso[0]);
        $fin = $dia->copy()->setTimeFromTimeString($descanso[1]);

        return $inicio->lt($hasta) && $fin->gt($desde);
    }

    /** «60 min, de 12:00 a 13:00» o «60 min». */
    public function descansoTexto(): string
    {
        $descanso = $this->descanso();

        return (int) $this->break_minutes . ' min' . ($descanso ? ', de ' . $descanso[0] . ' a ' . $descanso[1] : '');
    }

    protected function casts(): array
    {
        return [
            'effective_from'  => 'date',
            'effective_until' => 'date',
        ];
    }

    public const PRESENCIAL = 'presencial';
    public const REMOTA     = 'remota';

    /**
     * Presencial abre el laboratorio; remota no.
     *
     * La franja atendida se deriva de las jornadas, y de ella dependen si se
     * puede reservar y quién acompaña. Alguien desde casa cumple su jornada,
     * pero no abre la puerta.
     */
    public const MODALIDADES = [
        self::PRESENCIAL => 'Presencial',
        self::REMOTA     => 'Remota',
    ];

    public function esPresencial(): bool
    {
        return $this->modalidad === self::PRESENCIAL;
    }

    public const DIAS = [
        1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves',
        5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Vigente en una fecha dada: el contrato puede haber cambiado. */
    public function scopeVigenteEn(Builder $q, \DateTimeInterface $fecha): Builder
    {
        return $q->whereDate('effective_from', '<=', $fecha)
            ->where(fn (Builder $s) => $s->whereNull('effective_until')
                ->orWhereDate('effective_until', '>=', $fecha));
    }

    /** Horas efectivas del día, descontando el descanso. */
    public function horasEfectivas(): float
    {
        [$hi, $mi] = array_map('intval', explode(':', $this->starts_at));
        [$hf, $mf] = array_map('intval', explode(':', $this->ends_at));

        $minutos = (($hf * 60 + $mf) - ($hi * 60 + $mi)) - $this->break_minutes;

        return round($minutos / 60, 2);
    }
}
