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
 *
 * Tres topes, y los tres se miran: 2 h por día, 12 h por semana (lunes a
 * domingo) y 48 h por periodo. El periodo no es el mes calendario: la nómina
 * corta el 15, así que va del 16 de un mes al 15 del siguiente. Contar por
 * mes calendario dejaba que alguien hiciera 48 h del 16 al 31 y otras 48 del
 * 1 al 15, y las dos mitades caían en el mismo pago.
 */
class OvertimeService
{
    public function minutosSemana(User $user, ?Carbon $fecha = null): int
    {
        $f = $this->enZonaDelLab($fecha);

        return $this->acumulado($user, $f->copy()->startOfWeek(), $f->copy()->endOfWeek());
    }

    /** El día que se pide llega de 2 horas como máximo. */
    public function minutosDia(User $user, ?Carbon $fecha = null): int
    {
        $f = $this->enZonaDelLab($fecha);

        return $this->acumulado($user, $f->copy()->startOfDay(), $f->copy()->endOfDay());
    }

    /**
     * Las del periodo de corte (del 16 al 15), no las del mes calendario.
     * Se llama «mes» porque es el tope mensual; ver periodoDe().
     */
    public function minutosMes(User $user, ?Carbon $fecha = null): int
    {
        [$desde, $hasta] = self::periodoDe($this->enZonaDelLab($fecha));

        return $this->acumulado($user, $desde, $hasta);
    }

    /**
     * El periodo de corte al que pertenece una fecha: del 16 de un mes a las
     * 23:59 del 15 del siguiente, en la hora del laboratorio.
     *
     * @return array{0:Carbon,1:Carbon}
     */
    public static function periodoDe(?Carbon $fecha = null): array
    {
        $f = ($fecha ? $fecha->copy() : now())->setTimezone(config('fabos.lab.timezone'));
        $corte = (int) config('fabos.overtime.dia_de_corte', 15);

        $desde = $f->day > $corte
            ? $f->copy()->startOfMonth()->day($corte + 1)
            : $f->copy()->startOfMonth()->subMonthNoOverflow()->day($corte + 1);

        $hasta = $desde->copy()->addMonthNoOverflow()->day($corte)->endOfDay();

        return [$desde->startOfDay(), $hasta];
    }

    public function disponibleDia(User $user, ?Carbon $fecha = null): int
    {
        return max(0, config('fabos.overtime.max_dia_minutos') - $this->minutosDia($user, $fecha));
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

        if ($minutos > ($dia = $this->disponibleDia($user, $desde))) {
            return $this->mensaje($user->name, 'ese día', $dia, $minutos);
        }

        if ($minutos > ($sem = $this->disponibleSemana($user, $desde))) {
            return $this->mensaje($user->name, 'esta semana', $sem, $minutos);
        }

        if ($minutos > ($mes = $this->disponibleMes($user, $desde))) {
            [$d, $h] = self::periodoDe($desde);

            return $this->mensaje($user->name, 'en el corte del ' . $d->format('d/m') . ' al ' . $h->format('d/m'), $mes, $minutos);
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

    /**
     * El contador del periodo: por persona, cuánto lleva en el corte, en la
     * semana y hoy, cuánto le queda, y si algún día o semana del corte se pasó
     * del tope —lo que se programó antes de que hubiera tope diario, o a
     * mano—.
     *
     * @param  Collection<int,User>  $personas
     * @return Collection<int,array<string,mixed>>
     */
    public function resumen(Collection $personas, ?Carbon $fecha = null): Collection
    {
        $f = $this->enZonaDelLab($fecha);
        [$desde, $hasta] = self::periodoDe($f);

        $topeDia = (int) config('fabos.overtime.max_dia_minutos');
        $topeSemana = (int) config('fabos.overtime.max_semana_minutos');
        $topeMes = (int) config('fabos.overtime.max_mes_minutos');

        return $personas->map(function (User $u) use ($f, $desde, $hasta, $topeDia, $topeSemana, $topeMes) {
            $jornadas = $this->jornadas($u, $desde, $hasta);
            $tz = config('fabos.lab.timezone');

            $porDia = $jornadas->groupBy(fn (ShiftAssignment $s) => $s->starts_at->copy()->setTimezone($tz)->toDateString())
                ->map(fn ($g) => $g->sum(fn (ShiftAssignment $s) => $s->minutos()));

            $porSemana = $jornadas->groupBy(fn (ShiftAssignment $s) => $s->starts_at->copy()->setTimezone($tz)->startOfWeek()->toDateString())
                ->map(fn ($g) => $g->sum(fn (ShiftAssignment $s) => $s->minutos()));

            $periodo = (int) $porDia->sum();

            return [
                'persona'        => $u,
                'periodo'        => $periodo,
                'semana'         => $this->minutosSemana($u, $f),
                'hoy'            => $this->minutosDia($u, $f),
                'disponible'     => max(0, $topeMes - $periodo),
                'jornadas'       => $jornadas->count(),
                'dias_excedidos' => $porDia->filter(fn ($m) => $m > $topeDia)->count(),
                'semanas_excedidas' => $porSemana->filter(fn ($m) => $m > $topeSemana)->count(),
                'excede_periodo' => $periodo > $topeMes,
            ];
        })
            ->sortByDesc('periodo')
            ->values();
    }

    /** @return Collection<int,ShiftAssignment> */
    private function jornadas(User $user, Carbon $desde, Carbon $hasta): Collection
    {
        return ShiftAssignment::query()
            ->where('user_id', $user->id)
            ->where('counts_as_overtime', true)
            ->whereBetween('starts_at', [$desde->copy()->utc(), $hasta->copy()->utc()])
            ->get();
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
