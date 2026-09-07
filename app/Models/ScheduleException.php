<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ausencia de una persona, o cierre de todo el laboratorio (§5).
 *
 * De dias enteros —vacaciones, incapacidad, festivo—, o de **una franja del
 * dia**: la clase de ingles de los jueves de cuatro a cinco, una cita el
 * martes a las diez. Una franja puede ir en unas fechas concretas o repetirse
 * cada semana, el mismo dia, hasta una fecha o hasta nuevo aviso.
 *
 * En esa franja la persona no esta: no se le ofrece para asesorias, no se le
 * asigna un acompanamiento, y si alguien la elige a mano se le dice por que
 * no. Sin persona, es el laboratorio entero el que no esta.
 */
class ScheduleException extends Model
{
    protected $fillable = [
        'user_id', 'kind', 'starts_on', 'ends_on', 'starts_time', 'ends_time', 'weekday', 'note',
    ];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }

    public const TIPOS = [
        'vacaciones'   => 'Vacaciones',
        'incapacidad'  => 'Incapacidad',
        'permiso'      => 'Permiso',
        'comision'     => 'Comisión',
        'bloqueo'      => 'Bloqueo de agenda',
        'festivo'      => 'Festivo',
        'cierre'       => 'Cierre del laboratorio',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Sin persona, aplica a todo el laboratorio. */
    public function esGeneral(): bool
    {
        return $this->user_id === null;
    }

    /** De una franja del dia, no de dias enteros. */
    public function esDeFranja(): bool
    {
        return filled($this->starts_time) && filled($this->ends_time);
    }

    /** Se repite cada semana el mismo dia. */
    public function seRepite(): bool
    {
        return $this->esDeFranja() && $this->weekday !== null;
    }

    /**
     * Si esta franja ocupa algo del intervalo pedido.
     *
     * Se mira dia por dia entre el inicio y el fin del intervalo —casi
     * siempre es uno solo—, y en cada uno se pone la franja en el reloj del
     * laboratorio y se compara. Tocarse por el borde no es solaparse: una
     * clase que termina a las 17:00 no impide una asesoria a las 17:00.
     */
    public function ocupa(CarbonInterface $desde, CarbonInterface $hasta): bool
    {
        if (! $this->esDeFranja()) {
            return false;
        }

        $tz = config('fabos.lab.timezone');
        $d = $desde->copy()->setTimezone($tz);
        $h = $hasta->copy()->setTimezone($tz);

        for ($dia = $d->copy()->startOfDay(); $dia->lessThanOrEqualTo($h); $dia->addDay()) {
            if ($this->weekday !== null && $dia->isoWeekday() !== (int) $this->weekday) {
                continue;
            }

            // Por FECHA, no por instante: las fechas guardadas nacen a
            // medianoche UTC y el dia que se recorre a medianoche de Bogota.
            // Compararlos como instantes corria el fin un dia hacia atras.
            $fecha = $dia->toDateString();

            if ($fecha < $this->starts_on->toDateString() || ($this->ends_on && $fecha > $this->ends_on->toDateString())) {
                continue;
            }

            $inicio = $dia->copy()->setTimeFromTimeString($this->starts_time);
            $fin = $dia->copy()->setTimeFromTimeString($this->ends_time);

            if ($inicio->lt($h) && $fin->gt($d)) {
                return true;
            }
        }

        return false;
    }

    /** Cuando aplica, dicho en una linea. */
    public function cuando(): string
    {
        $fecha = fn (?Carbon $f) => $f?->format('d/m/Y');

        if ($this->seRepite()) {
            $dias = 'Cada ' . mb_strtolower(WorkSchedule::DIAS[(int) $this->weekday] ?? '');

            return $dias . ' de ' . substr($this->starts_time, 0, 5) . ' a ' . substr($this->ends_time, 0, 5)
                . ', desde el ' . $fecha($this->starts_on)
                . ($this->ends_on ? ' hasta el ' . $fecha($this->ends_on) : ', hasta nuevo aviso');
        }

        $rango = $this->ends_on && ! $this->ends_on->isSameDay($this->starts_on)
            ? 'Del ' . $fecha($this->starts_on) . ' al ' . $fecha($this->ends_on)
            : ($this->ends_on ? 'El ' . $fecha($this->starts_on) : 'Desde el ' . $fecha($this->starts_on));

        return $this->esDeFranja()
            ? $rango . ', de ' . substr($this->starts_time, 0, 5) . ' a ' . substr($this->ends_time, 0, 5)
            : $rango;
    }

    /** Por que, para decirselo a quien intenta asignar esa hora. */
    public function motivo(): string
    {
        return $this->note ?: (self::TIPOS[$this->kind] ?? $this->kind);
    }
}
