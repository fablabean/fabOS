<?php

namespace App\Services\Booking;

use App\Models\Asset;
use App\Models\Project;
use App\Models\Reservation;
use App\Models\Space;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Cómo está ocupado el laboratorio en una semana (§10, §11).
 *
 * El Gantt de un proyecto dice en qué semana va cada tarea; no dice si el
 * martes a las diez la sala de corte está libre ni quién estará ahí. Para
 * planear el trabajo de un proyecto hay que ver las reservas de todos, porque
 * el espacio es de todos: una semana del proyecto dibujada sola parece vacía.
 *
 * Devuelve los bloques ya repartidos en carriles: dos reservas a la misma hora
 * se dibujan una al lado de la otra, no una encima de la otra.
 */
class OcupacionSemanal
{
    /** Lo que no ocupa nada: se pidió y no fue, o no se dio. */
    private const SIN_OCUPAR = ['rechazada', 'cancelada'];

    /**
     * @return array{
     *   desde:Carbon, hasta:Carbon, dias:list<Carbon>, horaDesde:int, horaHasta:int,
     *   bloques:array<string,list<array<string,mixed>>>, espacios:Collection<int,Space>,
     * }
     */
    public function semana(
        Carbon $cualquierDia,
        ?Project $resaltar = null,
        ?int $espacio = null,
        bool $soloDelProyecto = false,
        bool $conQuienReserva = true,
    ): array {
        $tz = config('fabos.lab.timezone');

        $desde = $cualquierDia->copy()->timezone($tz)->startOfWeek(Carbon::MONDAY);
        $hasta = $desde->copy()->endOfWeek(Carbon::SUNDAY);

        $reservas = Reservation::query()
            ->with(['reservable', 'user', 'supervisor', 'companions', 'project', 'task'])
            // Las hijas —la herramienta tomada dentro de la sala, el bloque de
            // quien acompaña— son parte de la madre: dibujarlas aparte hace
            // que una actividad parezca dos.
            ->whereNull('parent_reservation_id')
            ->whereNotIn('status', self::SIN_OCUPAR)
            // Por solapamiento, y con los extremos pasados a UTC: la franja de
            // la tarde en Bogotá ya es el día siguiente en UTC.
            ->where('starts_at', '<', $hasta->copy()->utc())
            ->where('ends_at', '>', $desde->copy()->utc())
            ->when($soloDelProyecto && $resaltar, fn ($q) => $q->where('project_id', $resaltar->id))
            ->when($espacio, fn ($q) => $q->where(fn ($q) => $q
                ->where(fn ($s) => $s->where('reservable_type', Space::class)->where('reservable_id', $espacio))
                ->orWhere(fn ($a) => $a->where('reservable_type', Asset::class)
                    ->whereIn('reservable_id', Asset::where('space_id', $espacio)->select('id')))))
            ->orderBy('starts_at')
            ->get();

        $bloques = [];
        $horaDesde = 7;
        $horaHasta = 19;

        foreach ($reservas as $r) {
            $inicio = $r->starts_at->copy()->timezone($tz);
            $fin = $r->ends_at->copy()->timezone($tz);

            // Una reserva que cruza la medianoche se parte en un bloque por día.
            for ($dia = $inicio->copy()->startOfDay(); $dia->lt($fin) && $dia->lte($hasta); $dia->addDay()) {
                if ($dia->lt($desde)) {
                    continue;
                }

                $a = $inicio->max($dia);
                $b = $fin->min($dia->copy()->addDay());

                if ($a->gte($b)) {
                    continue;
                }

                $minA = $a->hour * 60 + $a->minute;
                $minB = $b->isSameDay($a) ? $b->hour * 60 + $b->minute : 24 * 60;

                $horaDesde = min($horaDesde, intdiv($minA, 60));
                $horaHasta = max($horaHasta, (int) ceil($minB / 60));

                $bloques[$dia->toDateString()][] = $this->bloque($r, $minA, $minB, $resaltar, $conQuienReserva);
            }
        }

        foreach ($bloques as $dia => $delDia) {
            $bloques[$dia] = $this->enCarriles($delDia);
        }

        // Lunes a sábado siempre; el domingo solo si algo pasa ese día.
        $dias = [];
        for ($d = $desde->copy(); $d->lte($hasta); $d->addDay()) {
            if ($d->isSunday() && empty($bloques[$d->toDateString()])) {
                continue;
            }
            $dias[] = $d->copy();
        }

        return [
            'desde'     => $desde,
            'hasta'     => $hasta,
            'dias'      => $dias,
            'horaDesde' => $horaDesde,
            'horaHasta' => min(24, $horaHasta),
            'bloques'   => $bloques,
            'espacios'  => Space::query()->where('is_reservable', true)->orderBy('name')->get(['id', 'name']),
        ];
    }

    /** @return array<string,mixed> */
    private function bloque(Reservation $r, int $minA, int $minB, ?Project $resaltar, bool $conQuienReserva): array
    {
        $delProyecto = $resaltar && (int) $r->project_id === $resaltar->id;

        return [
            'id'         => $r->id,
            'desde'      => $minA,
            'hasta'      => $minB,
            'hora'       => $this->reloj($minA) . '–' . $this->reloj($minB),
            'que'        => $this->queSeOcupa($r),
            'responsables' => $this->responsables($r),
            'reserva'    => $conQuienReserva || $delProyecto ? $r->user?->name : null,
            'para'       => $this->para($r, $delProyecto),
            'estado'     => $r->status,
            'estadoTxt'  => Reservation::ESTADOS[$r->status] ?? $r->status,
            'tipo'       => $r->esProduccion() ? 'produccion' : $r->tipoDeRecurso(),
            'delProyecto' => $delProyecto,
        ];
    }

    private function reloj(int $minutos): string
    {
        return sprintf('%02d:%02d', intdiv($minutos, 60), $minutos % 60);
    }

    /** La máquina con la sala donde está: «Láser 1 · Sala de corte». */
    private function queSeOcupa(Reservation $r): string
    {
        $nombre = $r->nombreDelRecurso();

        if ($r->esBloqueDeProyecto() || $r->esAtencionPersonal()) {
            return ($r->esBloqueDeProyecto() ? 'Tiempo de ' : 'Asesoría · ') . $nombre;
        }

        $sala = $r->reservable instanceof Asset ? $r->reservable->space?->name : null;

        return $sala ? $nombre . ' · ' . $sala : $nombre;
    }

    /**
     * Quién responde por la franja: quien asesora, quien acompaña la máquina,
     * quienes acompañan el espacio. Si nadie del equipo está asignado, quien
     * reservó es quien responde.
     *
     * @return list<string>
     */
    private function responsables(Reservation $r): array
    {
        $nombres = collect();

        if ($r->reservable instanceof User) {
            $nombres->push($r->reservable->name);
        }

        $nombres->push($r->supervisor?->name);
        $nombres = $nombres->merge($r->companions->pluck('name'));

        return $nombres->filter()->unique()->values()->all();
    }

    private function para(Reservation $r, bool $delProyecto): ?string
    {
        if ($r->task) {
            return $r->task->title;
        }

        if ($r->project && ! $delProyecto) {
            return $r->project->code;
        }

        return $r->purpose ? \Illuminate\Support\Str::limit($r->purpose, 60) : null;
    }

    /**
     * Reparte los bloques de un día en carriles para que no se tapen.
     *
     * Se agrupan los que se solapan en racimos; dentro de cada racimo, cada
     * bloque toma el primer carril libre, y todos los del racimo se dibujan
     * con el ancho que deja el número de carriles del racimo.
     *
     * @param  list<array<string,mixed>>  $bloques
     * @return list<array<string,mixed>>
     */
    private function enCarriles(array $bloques): array
    {
        usort($bloques, fn ($a, $b) => [$a['desde'], $b['hasta']] <=> [$b['desde'], $a['hasta']]);

        $salida = [];
        $racimo = [];
        $finDelRacimo = -1;

        $cerrar = function () use (&$racimo, &$salida) {
            $n = max(array_column($racimo, 'carril')) + 1;
            foreach ($racimo as $b) {
                $b['carriles'] = $n;
                $salida[] = $b;
            }
            $racimo = [];
        };

        $finDeCarril = [];

        foreach ($bloques as $b) {
            if ($racimo && $b['desde'] >= $finDelRacimo) {
                $cerrar();
                $finDeCarril = [];
            }

            $carril = 0;
            while (isset($finDeCarril[$carril]) && $finDeCarril[$carril] > $b['desde']) {
                $carril++;
            }

            $finDeCarril[$carril] = $b['hasta'];
            $b['carril'] = $carril;
            $racimo[] = $b;
            $finDelRacimo = max($finDelRacimo, $b['hasta']);
        }

        if ($racimo) {
            $cerrar();
        }

        return $salida;
    }
}
