<?php

namespace App\Services\Staffing;

use App\Models\ShiftAssignment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Contadores de horas extras (§5).
 *
 * El control es preventivo por diseño: se pregunta ANTES de programar a alguien
 * fuera de su jornada. Descubrirlo a fin de mes no evita nada, solo documenta
 * el incumplimiento.
 *
 * Las extras se acumulan de las jornadas programadas marcadas como tales; una
 * que se compensa con tiempo no consume del tope.
 */
class OvertimeService
{
    public function minutosSemana(User $user, ?Carbon $fecha = null): int
    {
        $f = $this->enZonaDelLab($fecha);

        return $this->acumulado($user, $f->copy()->startOfWeek(), $f->copy()->endOfWeek());
    }

    public function minutosMes(User $user, ?Carbon $fecha = null): int
    {
        $f = $this->enZonaDelLab($fecha);

        return $this->acumulado($user, $f->copy()->startOfMonth(), $f->copy()->endOfMonth());
    }

    public function disponibleSemana(User $user, ?Carbon $fecha = null): int
    {
        return max(0, config('fabos.overtime.max_semana_minutos') - $this->minutosSemana($user, $fecha));
    }

    public function disponibleMes(User $user, ?Carbon $fecha = null): int
    {
        return max(0, config('fabos.overtime.max_mes_minutos') - $this->minutosMes($user, $fecha));
    }

    /**
     * ¿Se puede programar a esta persona en esta franja?
     *
     * @return string|null motivo del rechazo, o null si cabe
     */
    public function motivoDeRechazo(User $user, Carbon $desde, Carbon $hasta, bool $cuentaComoExtra = true): ?string
    {
        if ($hasta->lessThanOrEqualTo($desde)) {
            return 'La hora de fin debe ser posterior a la de inicio.';
        }

        if (! $cuentaComoExtra) {
            return null;                     // se compensa con tiempo, no toca el tope
        }

        $minutos = (int) $desde->diffInMinutes($hasta);

        if ($minutos > ($sem = $this->disponibleSemana($user, $desde))) {
            return $this->mensaje($user->name, 'esta semana', $sem, $minutos);
        }

        if ($minutos > ($mes = $this->disponibleMes($user, $desde))) {
            return $this->mensaje($user->name, 'este mes', $mes, $minutos);
        }

        return null;
    }

    /**
     * Candidatos para cubrir una franja, del que menos extras lleva al que más.
     *
     * Es lo que convierte «a quién le pido el sábado» en una decisión con datos
     * y evita que el mismo termine cubriendo todos los sábados del semestre.
     *
     * @param  Collection<int,User>  $personas
     * @return Collection<int,array{persona:User,acumulado:int,disponible:int,puede:bool,motivo:?string}>
     */
    public function ordenarPorCarga(Collection $personas, Carbon $desde, Carbon $hasta): Collection
    {
        return $personas
            ->map(function (User $u) use ($desde, $hasta) {
                $motivo = $this->motivoDeRechazo($u, $desde, $hasta);

                return [
                    'persona'    => $u,
                    'acumulado'  => $this->minutosSemana($u, $desde),
                    'disponible' => $this->disponibleSemana($u, $desde),
                    'puede'      => $motivo === null,
                    'motivo'     => $motivo,
                ];
            })
            ->sortBy('acumulado')
            ->values();
    }

    private function acumulado(User $user, Carbon $desde, Carbon $hasta): int
    {
        return (int) ShiftAssignment::query()
            ->where('user_id', $user->id)
            ->where('counts_as_overtime', true)
            ->whereBetween('starts_at', [$desde->copy()->utc(), $hasta->copy()->utc()])
            ->get()
            ->sum(fn (ShiftAssignment $s) => $s->minutos());
    }

    private function enZonaDelLab(?Carbon $fecha): Carbon
    {
        return ($fecha ? $fecha->copy() : now())->setTimezone(config('fabos.lab.timezone'));
    }

    private function mensaje(string $nombre, string $periodo, int $disponible, int $pedidos): string
    {
        return $disponible === 0
            ? "{$nombre} ya agotó su tope de horas extras {$periodo}."
            : "{$nombre} solo tiene {$this->horas($disponible)} de extras disponibles {$periodo}, "
              . "y se piden {$this->horas($pedidos)}.";
    }

    private function horas(int $minutos): string
    {
        $h = intdiv($minutos, 60);
        $m = $minutos % 60;

        return $h && $m ? "{$h} h {$m} min" : ($h ? "{$h} h" : "{$m} min");
    }
}
