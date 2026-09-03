<?php

namespace App\Services\Staffing;

use App\Models\Asset;
use App\Models\Certifab;
use App\Models\ScheduleException;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Quién está en jornada, y por tanto cuándo hay cobertura (§5).
 *
 * La franja atendida del laboratorio no se digita: se DERIVA de las jornadas
 * vigentes. Por eso unas vacaciones la encogen solas, sin que nadie recuerde
 * actualizar un campo.
 */
class CoverageService
{
    /**
     * Personal en jornada durante todo el intervalo pedido.
     *
     * @return Collection<int,User>
     */
    /**
     * @param  bool  $incluirRemota  contar tambien a quien esta en jornada remota. Vale
     *                               para lo virtual: una sala de VR la atiende alguien
     *                               desde casa; una fresadora, no.
     */
    public function enJornada(CarbonInterface $desde, CarbonInterface $hasta, bool $incluirRemota = false): Collection
    {
        $tz = config('fabos.lab.timezone');
        $d  = $desde->copy()->setTimezone($tz);
        $h  = $hasta->copy()->setTimezone($tz);

        // Un cierre del laboratorio deja a todo el mundo fuera.
        if ($this->hayCierreGeneral($d)) {
            return collect();
        }

        $porPatron = $this->porPatronSemanal($d, $h, $incluirRemota);
        $porTurno  = $this->porTurnoProgramado($desde, $hasta);

        return $porPatron->merge($porTurno)->unique('id')->values();
    }

    /**
     * Colaboradores que además pueden acompañar el uso de un equipo: hay que
     * estar en jornada Y tener el certifab correspondiente (§10).
     *
     * @return Collection<int,User>
     */
    public function acompanantesPara(Asset $asset, CarbonInterface $desde, CarbonInterface $hasta): Collection
    {
        $enJornada = $this->enJornada($desde, $hasta);

        if ($enJornada->isEmpty()) {
            return collect();
        }

        $habilitados = Certifab::query()
            ->vigente()
            ->whereIn('user_id', $enJornada->pluck('id'))
            ->where(fn ($q) => $q->where('asset_id', $asset->id)
                ->orWhere('risk_family_id', $asset->risk_family_id))
            ->pluck('user_id')
            ->unique();

        return $enJornada->whereIn('id', $habilitados)->values();
    }

    /**
     * Quién está certificado para acompañar este equipo, **esté o no en
     * jornada** (§10).
     *
     * Sirve para la bandeja de solicitudes: cuando alguien pide un sábado no
     * hay nadie en jornada por definición, y aun así hay que poder ofrecer a
     * quién llamar. Quien decide ve la lista y asume el costo de abrirle el
     * día; para reservar en horario normal se sigue usando `acompanantesPara`,
     * que exige estar en jornada.
     *
     * @return Collection<int,User>
     */
    public function certificadosPara(Asset $asset): Collection
    {
        $habilitados = Certifab::query()
            ->vigente()
            ->where(fn ($q) => $q->where('asset_id', $asset->id)
                ->orWhere('risk_family_id', $asset->risk_family_id))
            ->pluck('user_id')
            ->unique();

        return User::query()
            ->whereIn('id', $habilitados)
            ->whereHas('roles')          // solo quien trabaja en el laboratorio
            ->where('status', 'activo')
            ->orderBy('name')
            ->get();
    }

    public function hayCobertura(CarbonInterface $desde, CarbonInterface $hasta, bool $incluirRemota = false): bool
    {
        return $this->enJornada($desde, $hasta, $incluirRemota)->isNotEmpty();
    }

    /**
     * Envolvente de las jornadas de un día: la franja atendida.
     * Devuelve null cuando ese día no hay nadie.
     *
     * @return array{0:string,1:string}|null
     */
    public function franjaAtendida(CarbonInterface $dia): ?array
    {
        // Ojo: aquí llega una FECHA de calendario, no un instante. Convertirle
        // la zona horaria la correría al día anterior —medianoche UTC es la
        // tarde del día previo en Bogotá— y el día de la semana saldría mal.
        $fecha = Carbon::parse($dia->format('Y-m-d'), config('fabos.lab.timezone'))->startOfDay();

        if ($this->hayCierreGeneral($fecha)) {
            return null;
        }

        $ausentes = $this->ausentesEn($fecha);

        $jornadas = WorkSchedule::query()
            ->vigenteEn($fecha)
            ->where('weekday', $fecha->isoWeekday())
            ->where('modalidad', WorkSchedule::PRESENCIAL)
            ->whereNotIn('user_id', $ausentes)
            ->get();

        if ($jornadas->isEmpty()) {
            return null;
        }

        return [
            $jornadas->min('starts_at'),
            $jornadas->max('ends_at'),
        ];
    }

    /** @return Collection<int,User> */
    private function porPatronSemanal(CarbonInterface $d, CarbonInterface $h, bool $incluirRemota = false): Collection
    {
        // Un intervalo que cruza la medianoche no puede cubrirse con un solo
        // patrón diario; se resuelve por turno programado.
        if (! $d->isSameDay($h)) {
            return collect();
        }

        $ausentes = $this->ausentesEn($d);

        $ids = WorkSchedule::query()
            ->vigenteEn($d)
            ->where('weekday', $d->isoWeekday())
            // Una jornada remota es jornada, pero no cobertura: quien trabaja
            // desde casa no abre la puerta ni acompana una maquina. Salvo que
            // lo que se atiende sea virtual, y entonces si cuenta.
            ->when(! $incluirRemota, fn ($q) => $q->where('modalidad', WorkSchedule::PRESENCIAL))
            ->whereNotIn('user_id', $ausentes)
            ->where('starts_at', '<=', $d->format('H:i:s'))
            ->where('ends_at', '>=', $h->format('H:i:s'))
            ->pluck('user_id')
            ->unique();

        return User::whereIn('id', $ids)->where('status', 'activo')->get();
    }

    /** @return Collection<int,User> */
    private function porTurnoProgramado(CarbonInterface $desde, CarbonInterface $hasta): Collection
    {
        // A UTC antes de comparar: al enlazar un Carbon a la consulta se pierde
        // el desplazamiento y se compararía la hora de pared contra un instante.
        $ids = ShiftAssignment::query()
            ->where('starts_at', '<=', $desde->copy()->utc())
            ->where('ends_at', '>=', $hasta->copy()->utc())
            ->pluck('user_id')
            ->unique();

        return User::whereIn('id', $ids)->where('status', 'activo')->get();
    }

    /** @return array<int,int> ids de quienes están ausentes ese día */
    private function ausentesEn(CarbonInterface $dia): array
    {
        return ScheduleException::query()
            ->whereNotNull('user_id')
            ->whereDate('starts_on', '<=', $dia)
            ->whereDate('ends_on', '>=', $dia)
            ->pluck('user_id')
            ->all();
    }

    private function hayCierreGeneral(CarbonInterface $dia): bool
    {
        return ScheduleException::query()
            ->whereNull('user_id')
            ->whereDate('starts_on', '<=', $dia)
            ->whereDate('ends_on', '>=', $dia)
            ->exists();
    }
}
