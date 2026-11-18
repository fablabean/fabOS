<?php

namespace App\Services\Staffing;

use App\Filament\Resources\WorkSchedules\WorkScheduleResource;
use App\Models\Area;
use App\Models\JornadaDelDia;
use App\Models\ScheduleException;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Booking\BookingException;
use App\Support\Secciones;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * La programación de cada persona del equipo, día por día (§5).
 *
 * Responde una sola pregunta, para cualquiera y cualquier fecha: **¿en qué
 * horas trabaja y desde dónde?** Si esa fecha tiene bloques propios
 * (`jornadas_del_dia`), mandan esos; si no, su horario semanal. Una ausencia
 * de día entero (vacaciones, incapacidad) o un cierre del laboratorio la deja
 * sin bloques.
 *
 * La usan «Mi semana», «Semana del equipo», la regla del mínimo por área y
 * la cobertura de reservas y asesorías: una sola respuesta para todos.
 */
class Programacion
{
    /**
     * Los bloques de una persona en una fecha, ordenados.
     *
     * @return Collection<int,array{inicio:string,fin:string,modalidad:string,ajustado:bool,descanso:?array}>
     */
    public function bloquesDe(User $persona, CarbonInterface $fecha): Collection
    {
        return $this->bloquesDeVarios(collect([$persona]), $fecha)[$persona->id] ?? collect();
    }

    /**
     * Lo mismo para varias personas de una vez, con pocas consultas.
     *
     * @param  Collection<int,User>  $personas
     * @return array<int,Collection>
     */
    public function bloquesDeVarios(Collection $personas, CarbonInterface $fecha): array
    {
        $dia = $this->dia($fecha);
        $ids = $personas->pluck('id')->all();
        $resultado = array_fill_keys($ids, collect());

        if ($ids === [] || $this->hayCierre($dia)) {
            return $resultado;
        }

        $ausentes = $this->ausentes($dia);

        $ajustes = JornadaDelDia::query()
            ->whereIn('user_id', $ids)
            ->whereDate('fecha', $dia->toDateString())
            ->orderBy('starts_at')
            ->get()
            ->groupBy('user_id');

        $semanales = WorkSchedule::query()
            ->vigenteEn($dia)
            ->whereIn('user_id', $ids)
            ->where('weekday', $dia->isoWeekday())
            ->orderBy('starts_at')
            ->get()
            ->groupBy('user_id');

        foreach ($ids as $id) {
            if (isset($ausentes[$id])) {
                continue;
            }

            if ($ajustes->has($id)) {
                $resultado[$id] = $ajustes[$id]->map(fn (JornadaDelDia $j) => [
                    'inicio' => substr($j->starts_at, 0, 5),
                    'fin' => substr($j->ends_at, 0, 5),
                    'modalidad' => $j->modalidad,
                    'ajustado' => true,
                    'descanso' => null,
                ])->values();

                continue;
            }

            $resultado[$id] = ($semanales[$id] ?? collect())->map(fn (WorkSchedule $j) => [
                'inicio' => substr($j->starts_at, 0, 5),
                'fin' => substr($j->ends_at, 0, 5),
                'modalidad' => $j->modalidad,
                'ajustado' => false,
                'descanso' => $j->descanso(),
            ])->values();
        }

        return $resultado;
    }

    /**
     * La ausencia de día entero que tiene esa persona en esa fecha, si la tiene.
     *
     * @return array<int,string> user_id => tipo («Vacaciones»)
     */
    public function ausencias(CarbonInterface $fecha): array
    {
        return $this->ausentes($this->dia($fecha));
    }

    public function hayCierre(CarbonInterface $fecha): bool
    {
        $dia = $this->dia($fecha);

        return ScheduleException::query()
            ->whereNull('user_id')
            ->whereNull('starts_time')
            ->whereDate('starts_on', '<=', $dia)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $dia))
            ->exists();
    }

    /**
     * Quién es del equipo para esta vista: quien tiene horario semanal vigente
     * o algún bloque esa semana.
     *
     * @return Collection<int,User>
     */
    public function equipo(CarbonInterface $lunes): Collection
    {
        $lunes = $this->dia($lunes);
        $domingo = $lunes->copy()->addDays(6);

        $ids = WorkSchedule::query()
            ->whereDate('effective_from', '<=', $domingo)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $lunes))
            ->pluck('user_id')
            ->merge(JornadaDelDia::query()
                ->whereBetween('fecha', [$lunes->toDateString(), $domingo->toDateString()])
                ->pluck('user_id'))
            ->unique();

        return User::whereIn('id', $ids)->where('status', 'activo')->orderBy('name')->get();
    }

    /**
     * Las áreas de cada persona: las de las máquinas que asesora. No hay otra
     * lista de «quién es de qué área»: sería una segunda verdad.
     *
     * @param  list<int>  $ids
     * @return array<int,list<int>> user_id => area_ids
     */
    public function areasDe(array $ids): array
    {
        return DB::table('asset_advisors')
            ->join('assets', 'assets.id', '=', 'asset_advisors.asset_id')
            ->whereIn('asset_advisors.user_id', $ids)
            ->whereNull('assets.deleted_at')
            ->whereNotNull('assets.area_id')
            ->select('asset_advisors.user_id', 'assets.area_id')
            ->distinct()
            ->get()
            ->groupBy('user_id')
            ->map(fn ($filas) => $filas->pluck('area_id')->map(fn ($a) => (int) $a)->unique()->values()->all())
            ->all();
    }

    /**
     * Quién de cada área está presencial en algún momento de ese día.
     *
     * @return array<int,list<int>> area_id => user_ids
     */
    public function presencialesPorArea(CarbonInterface $fecha, ?Collection $personas = null): array
    {
        $personas ??= $this->equipo($this->dia($fecha)->startOfWeek(CarbonInterface::MONDAY));
        $areas = $this->areasDe($personas->pluck('id')->all());
        $bloques = $this->bloquesDeVarios($personas, $fecha);
        $porArea = [];

        foreach ($areas as $userId => $areaIds) {
            $presencial = ($bloques[$userId] ?? collect())->contains(fn ($b) => $b['modalidad'] === WorkSchedule::PRESENCIAL);

            foreach ($areaIds as $areaId) {
                $porArea[$areaId] ??= [];

                if ($presencial) {
                    $porArea[$areaId][] = $userId;
                }
            }
        }

        return $porArea;
    }

    /** Quien arma la programación de otros: puede editar los horarios. */
    public function puedeCoordinar(?User $quien): bool
    {
        return $quien !== null && $quien->puedeEnLaSeccion('editar', Secciones::claveDe(WorkScheduleResource::class));
    }

    /**
     * Guarda cómo trabaja una persona un día concreto.
     *
     * Reemplaza lo que tuviera esa fecha. La regla de la modalidad (normal,
     * híbrida, flexible) y la del mínimo presencial por área solo las puede
     * saltar la coordinación, y si salta la del mínimo queda anotado.
     *
     * @param  list<array{inicio:string,fin:string,modalidad:string}>  $bloques
     *
     * @throws BookingException con el motivo, para enseñarlo tal cual
     */
    public function guardarDia(User $persona, CarbonInterface $fecha, array $bloques, User $quien, bool $forzar = false, ?string $nota = null): void
    {
        $dia = $this->dia($fecha);
        $coordina = $this->puedeCoordinar($quien);

        if (! $coordina && $quien->id !== $persona->id) {
            throw new BookingException('Solo puedes cambiar tu propia semana.');
        }

        if (! $coordina && $dia->lt(Carbon::today(config('fabos.lab.timezone')))) {
            throw new BookingException('Ese día ya pasó.');
        }

        $bloques = $this->limpiar($bloques);

        if ($bloques === []) {
            throw new BookingException('Agrega al menos un bloque de horas.');
        }

        if (! $coordina) {
            $this->exigirModalidad($persona, $dia, $bloques);
        }

        $this->exigirMinimo($persona, $dia, $bloques, $coordina && $forzar);

        DB::transaction(function () use ($persona, $dia, $bloques, $quien, $forzar, $coordina, $nota) {
            JornadaDelDia::where('user_id', $persona->id)->whereDate('fecha', $dia->toDateString())->delete();

            foreach ($bloques as $b) {
                JornadaDelDia::create([
                    'user_id' => $persona->id,
                    'fecha' => $dia->toDateString(),
                    'starts_at' => $b['inicio'],
                    'ends_at' => $b['fin'],
                    'modalidad' => $b['modalidad'],
                    'nota' => $nota,
                    'forzado' => $coordina && $forzar,
                    'creado_por' => $quien->id,
                ]);
            }
        });
    }

    /** Vuelve ese día a su horario semanal. También pasa por la regla del mínimo. */
    public function volverAlHorario(User $persona, CarbonInterface $fecha, User $quien, bool $forzar = false): void
    {
        $dia = $this->dia($fecha);
        $coordina = $this->puedeCoordinar($quien);

        if (! $coordina && $quien->id !== $persona->id) {
            throw new BookingException('Solo puedes cambiar tu propia semana.');
        }

        $semanal = WorkSchedule::query()->vigenteEn($dia)->where('user_id', $persona->id)
            ->where('weekday', $dia->isoWeekday())->get()
            ->map(fn (WorkSchedule $j) => ['inicio' => substr($j->starts_at, 0, 5), 'fin' => substr($j->ends_at, 0, 5), 'modalidad' => $j->modalidad])
            ->all();

        $this->exigirMinimo($persona, $dia, $semanal, $coordina && $forzar);

        JornadaDelDia::where('user_id', $persona->id)->whereDate('fecha', $dia->toDateString())->delete();
    }

    /**
     * Lo que puede cambiar cada modalidad, por sí misma.
     *
     * @param  list<array{inicio:string,fin:string,modalidad:string}>  $bloques
     */
    private function exigirModalidad(User $persona, Carbon $dia, array $bloques): void
    {
        $modalidad = $persona->modalidadDeTrabajo();

        if ($modalidad === User::TRABAJO_NORMAL) {
            throw new BookingException('Tu modalidad es normal: tu horario es el de siempre, en el laboratorio. Si necesitas un cambio, pídeselo a la coordinación.');
        }

        if ($modalidad === User::TRABAJO_FLEXIBLE) {
            return;
        }

        // Híbrida: reparte entre presencial y remoto, dentro de su horario de ese día.
        $semanal = WorkSchedule::query()->vigenteEn($dia)->where('user_id', $persona->id)
            ->where('weekday', $dia->isoWeekday())->get();

        if ($semanal->isEmpty()) {
            throw new BookingException('Ese día no tienes jornada en tu horario. Con modalidad híbrida puedes repartir tus horas entre presencial y remoto, pero no agregar días: eso lo hace la coordinación.');
        }

        $desde = substr($semanal->min('starts_at'), 0, 5);
        $hasta = substr($semanal->max('ends_at'), 0, 5);

        foreach ($bloques as $b) {
            if ($b['inicio'] < $desde || $b['fin'] > $hasta) {
                throw new BookingException("Con modalidad híbrida el horario se queda igual ({$desde} a {$hasta}); lo que cambias es si cada parte es presencial o remota. Para mover el horario hace falta la modalidad flexible.");
            }
        }
    }

    /**
     * Siempre un profesional presencial por área (§5).
     *
     * Si con el cambio un área que ese día tenía a alguien presencial se queda
     * sin nadie, no se acepta; la coordinación puede forzarlo.
     *
     * @param  list<array{inicio:string,fin:string,modalidad:string}>  $bloques
     */
    private function exigirMinimo(User $persona, Carbon $dia, array $bloques, bool $forzar): void
    {
        if ($forzar) {
            return;
        }

        $seguiraPresencial = collect($bloques)->contains(fn ($b) => $b['modalidad'] === WorkSchedule::PRESENCIAL);

        if ($seguiraPresencial) {
            return;
        }

        $misAreas = $this->areasDe([$persona->id])[$persona->id] ?? [];

        if ($misAreas === []) {
            return;
        }

        $presenciales = $this->presencialesPorArea($dia, $this->equipo($dia->copy()->startOfWeek(CarbonInterface::MONDAY))->push($persona)->unique('id'));

        foreach ($misAreas as $areaId) {
            $quienes = $presenciales[$areaId] ?? [];

            // Solo importa si hoy la sostiene ella sola.
            if ($quienes === [$persona->id]) {
                $area = Area::find($areaId)?->name ?? 'tu área';
                $otros = $this->companerosDeArea($areaId, $persona->id);

                throw new BookingException(
                    "Ese día serías la única persona presencial de {$area}, y siempre tiene que haber al menos una. "
                    . ($otros ? 'Habla con ' . $otros . ' para que alguien vaya, o pídeselo a la coordinación.' : 'Pídeselo a la coordinación.')
                );
            }
        }
    }

    private function companerosDeArea(int $areaId, int $sinId): string
    {
        $nombres = User::query()
            ->whereIn('id', DB::table('asset_advisors')->join('assets', 'assets.id', '=', 'asset_advisors.asset_id')
                ->where('assets.area_id', $areaId)->whereNull('assets.deleted_at')->select('asset_advisors.user_id'))
            ->where('id', '!=', $sinId)
            ->where('status', 'activo')
            ->orderBy('name')
            ->limit(3)
            ->pluck('name')
            ->all();

        return $nombres === [] ? '' : implode(', ', $nombres);
    }

    /**
     * @param  array<int,mixed>  $bloques
     * @return list<array{inicio:string,fin:string,modalidad:string}>
     */
    private function limpiar(array $bloques): array
    {
        $limpios = [];

        foreach ($bloques as $b) {
            $inicio = substr(trim((string) ($b['inicio'] ?? '')), 0, 5);
            $fin = substr(trim((string) ($b['fin'] ?? '')), 0, 5);
            $modalidad = (string) ($b['modalidad'] ?? '');

            if ($inicio === '' && $fin === '') {
                continue;
            }

            if (! preg_match('/^\d{2}:\d{2}$/', $inicio) || ! preg_match('/^\d{2}:\d{2}$/', $fin)) {
                throw new BookingException('Las horas van en formato de 24 horas: 08:00, 14:30.');
            }

            if ($fin <= $inicio) {
                throw new BookingException("El bloque de {$inicio} termina antes de empezar.");
            }

            if (! isset(WorkSchedule::MODALIDADES[$modalidad])) {
                throw new BookingException('Cada bloque es presencial o remoto.');
            }

            $limpios[] = ['inicio' => $inicio, 'fin' => $fin, 'modalidad' => $modalidad];
        }

        usort($limpios, fn ($a, $b) => strcmp($a['inicio'], $b['inicio']));

        for ($i = 1; $i < count($limpios); $i++) {
            if ($limpios[$i]['inicio'] < $limpios[$i - 1]['fin']) {
                throw new BookingException("Los bloques se pisan: {$limpios[$i - 1]['inicio']}–{$limpios[$i - 1]['fin']} y {$limpios[$i]['inicio']}–{$limpios[$i]['fin']}.");
            }
        }

        return $limpios;
    }

    /** @return array<int,string> */
    private function ausentes(Carbon $dia): array
    {
        return ScheduleException::query()
            ->whereNotNull('user_id')
            ->whereNull('starts_time')
            ->whereDate('starts_on', '<=', $dia)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $dia))
            ->get(['user_id', 'kind'])
            ->mapWithKeys(fn (ScheduleException $e) => [(int) $e->user_id => ScheduleException::TIPOS[$e->kind] ?? 'Ausente'])
            ->all();
    }

    /** Una FECHA de calendario en el reloj del laboratorio, sin correrla de día. */
    private function dia(CarbonInterface $fecha): Carbon
    {
        return Carbon::parse($fecha->format('Y-m-d'), config('fabos.lab.timezone'))->startOfDay();
    }
}
