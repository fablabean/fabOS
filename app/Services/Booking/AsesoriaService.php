<?php

namespace App\Services\Booking;

use App\Models\Area;
use App\Models\Asset;
use App\Models\AssetAdvisor;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Staffing\CoverageService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Asesorías: la puerta para quien todavía no tiene el certifab (§10).
 *
 * El sistema ya prometía esto —cuando alguien no está habilitado, la pantalla
 * le responde «Asesoría con el responsable del equipo»— y no existía forma de
 * pedirla. Esto la cierra.
 *
 * Una asesoría **es una reserva del tiempo de quien asesora**: `reservable` es
 * esa persona, y el equipo del que se habla va aparte. La máquina no se
 * bloquea, porque muchas asesorías son de consulta —revisar un diseño, planear
 * un trabajo— y dejarla parada no le sirve a nadie.
 */
class AsesoriaService
{
    public function __construct(
        private CoverageService $cobertura,
        private BookingService $reservas,
    ) {}

    /**
     * Quién está declarado para asesorar sobre este equipo.
     *
     * @return Collection<int,User>
     */
    public function asesoresDe(Asset|Area $ambito): Collection
    {
        if ($ambito instanceof Asset) {
            return $ambito->advisors()->where('users.status', 'activo')->get();
        }

        /*
         * De un area asesora quien asesore CUALQUIERA de sus maquinas.
         *
         * No hay una lista aparte de «asesores del area» a proposito: seria
         * una segunda verdad que se separaria de la primera en cuanto alguien
         * entre o salga del equipo de una maquina.
         */
        return User::query()
            ->where('users.status', 'activo')
            ->whereIn('id', AssetAdvisor::query()
                ->whereIn('asset_id', Asset::where('area_id', $ambito->id)->select('id'))
                ->select('user_id'))
            ->get();
    }

    /**
     * Quién puede atender **esa franja concreta**.
     *
     * Tres condiciones, y las tres importan: estar declarada para el equipo,
     * estar en jornada presencial —una remota cumple su horario pero no atiende
     * a nadie en el laboratorio— y tener la franja libre.
     *
     * @return Collection<int,User>
     */
    public function disponiblesPara(
        Asset|Area $ambito,
        CarbonInterface $desde,
        CarbonInterface $hasta,
        ?User $solicitante = null,
    ): Collection {
        $declarados = $this->asesoresDe($ambito);

        if ($declarados->isEmpty()) {
            return collect();
        }

        $enJornada = $this->cobertura->enJornada($desde, $hasta)->pluck('id');

        return $declarados
            ->whereIn('id', $enJornada)
            // Nadie se asesora a sí mismo: si quien pide es del equipo, se
            // busca a otra persona.
            ->when($solicitante, fn (Collection $c) => $c->where('id', '!=', $solicitante->id))
            ->filter(fn (User $u) => $this->reservas->estaLibre(User::class, $u->id, $desde, $hasta))
            ->values();
    }

    /**
     * A quién le toca.
     *
     * La regla, en orden:
     *
     *  1. **Si hay responsable declarada, es suya.** Para eso se marca.
     *  2. Si no, a quien **menos asesorías lleva** de este equipo.
     *  3. Si empatan, a quien hace **más tiempo** que no atiende una.
     *  4. Si vuelven a empatar, por identificador, para que sea determinista.
     *
     * No se lleva un «ciclo» explícito a propósito: un contador de vueltas se
     * desincroniza en cuanto alguien se enferma o entra al equipo, y hay que
     * repararlo a mano. Contar lo ya hecho produce el mismo turno rotativo y se
     * recupera solo.
     */
    public function elegir(
        Asset|Area $ambito,
        CarbonInterface $desde,
        CarbonInterface $hasta,
        ?User $solicitante = null,
    ): ?User {
        $candidatos = $this->disponiblesPara($ambito, $desde, $hasta, $solicitante);

        if ($candidatos->isEmpty()) {
            return null;
        }

        /*
         * El responsable manda, pero solo de SU maquina.
         *
         * En una asesoria general del area no hay una persona marcada: nadie
         * responde por «impresion 3D» en conjunto. Ahi vale el turno a secas,
         * que es justo lo que reparte bien cuando no hay una regla mejor.
         */
        if ($ambito instanceof Asset) {
            $responsables = $candidatos->filter(
                fn (User $u) => (bool) $u->pivot?->es_responsable,
            );

            if ($responsables->isNotEmpty()) {
                $candidatos = $responsables->values();
            }
        }

        $historial = $this->historialDe($ambito, $candidatos->pluck('id')->all());

        // Una sola funcion que devuelve la tupla de desempate, y PHP compara
        // arrays elemento a elemento.
        //
        // Con `sortBy([fn, fn, fn])` no funciona: desde Laravel 10 un array de
        // funciones se interpreta como COMPARADORES `fn($a, $b)`, no como
        // extractores de clave, y el resultado es un orden arbitrario que
        // parece correcto en las primeras vueltas.
        return $candidatos
            ->sortBy(fn (User $u) => [
                $historial[$u->id]['cuantas'] ?? 0,
                $historial[$u->id]['ultima'] ?? '',
                $u->id,
            ])
            ->first();
    }

    /**
     * Agenda la asesoría, o devuelve null si nadie puede atenderla.
     *
     * Todo dentro de una transacción: entre elegir a alguien y reservarle la
     * hora puede colarse otra petición y dejar a esa persona con dos asesorías
     * a la misma hora.
     */
    public function agendar(
        User $solicitante,
        Asset|Area $ambito,
        CarbonInterface $desde,
        CarbonInterface $hasta,
        ?string $motivo = null,
    ): ?Reservation {
        return DB::transaction(function () use ($solicitante, $ambito, $desde, $hasta, $motivo) {
            // Quien pide tambien tiene que estar libre. Se comprobaba solo al
            // asesor, asi que una misma persona podia agendarse dos asesorias a
            // la misma hora —con dos asesores distintos— y dejar plantado a uno.
            //
            // No basta con mirar `reservable`: las reservas de esa persona sobre
            // una maquina la tienen como `user_id`, no como reservable.
            if ($this->tieneAlgoALaMismaHora($solicitante, $desde, $hasta)) {
                return null;
            }

            $asesor = $this->elegir($ambito, $desde, $hasta, $solicitante);

            if (! $asesor) {
                return null;
            }

            return Reservation::create([
                'reservable_type'   => User::class,
                'reservable_id'     => $asesor->id,
                'user_id'           => $solicitante->id,
                'advisory_asset_id' => $ambito instanceof Asset ? $ambito->id : null,
                'advisory_area_id'  => $ambito instanceof Area ? $ambito->id : null,
                'mode'              => 'asesoria',
                'status'            => 'confirmada',
                'starts_at'         => $desde,
                'ends_at'           => $hasta,
                'purpose'           => $motivo,
            ]);
        });
    }

    /**
     * Cuántas asesorías lleva cada quien de este equipo, y cuándo fue la última.
     *
     * Se cuenta **por equipo** y no en total: el turno que describe la
     * coordinación es entre quienes asesoran esa máquina. Las canceladas y las
     * rechazadas no cuentan — no se atendieron.
     *
     * @param  list<int>  $userIds
     * @return array<int,array{cuantas:int,ultima:?string}>
     */
    public function historialDe(Asset|Area $ambito, array $userIds = []): array
    {
        return Reservation::query()
            ->where('mode', 'asesoria')
            ->when(
                $ambito instanceof Asset,
                fn ($q) => $q->where('advisory_asset_id', $ambito->id),
                // El turno de las generales se cuenta entre ellas: mezclarlo
                // con el de cada maquina haria que quien asesora mucho una
                // Prusa nunca cayera en una general, y al reves.
                fn ($q) => $q->where('advisory_area_id', $ambito->id),
            )
            ->where('reservable_type', User::class)
            ->whereNotIn('status', ['cancelada', 'rechazada'])
            ->when($userIds !== [], fn ($q) => $q->whereIn('reservable_id', $userIds))
            ->selectRaw('reservable_id, COUNT(*) AS cuantas, MAX(starts_at) AS ultima')
            ->groupBy('reservable_id')
            ->get()
            ->mapWithKeys(fn ($f) => [
                (int) $f->reservable_id => [
                    'cuantas' => (int) $f->cuantas,
                    'ultima'  => $f->ultima,
                ],
            ])
            ->all();
    }

    /**
     * Horas en las que alguien puede atender, de aqui a unos dias.
     *
     * Se ofrecen **solo franjas con cupo**: pedir algo que despues nadie puede
     * cumplir genera una espera y un rechazo, y las dos cosas cuestan mas que
     * no ofrecerlo.
     *
     * Es deliberadamente una comprobacion por franja y no una consulta lista:
     * la disponibilidad depende de la jornada, de la modalidad, de las
     * ausencias y de lo que cada persona ya tenga reservado, y todo eso ya lo
     * sabe responder el resto del sistema. Reimplementarlo en SQL seria una
     * segunda verdad que se separaria de la primera.
     *
     * @return Collection<int,array{inicio:CarbonInterface,fin:CarbonInterface,cuantos:int}>
     */
    public function franjasDisponibles(
        Asset|Area $ambito,
        ?User $solicitante = null,
        int $dias = 7,
        ?int $minutos = null,
    ): Collection {
        if ($this->asesoresDe($ambito)->isEmpty()) {
            return collect();
        }

        $minutos = $minutos ?? (int) config('fabos.asesorias.minutos', 45);
        $tz = config('fabos.lab.timezone');
        $ahora = Carbon::now($tz);

        $franjas = collect();

        for ($i = 0; $i < $dias; $i++) {
            $dia = $ahora->copy()->addDays($i)->startOfDay();
            $atendido = $this->cobertura->franjaAtendida($dia);

            // Dia sin cobertura presencial: ni se mira.
            if (! $atendido) {
                continue;
            }

            [$abre, $cierra] = $atendido;

            $inicio = $dia->copy()->setTimeFromTimeString($abre);
            $fin    = $dia->copy()->setTimeFromTimeString($cierra);

            while ($inicio->copy()->addMinutes($minutos)->lessThanOrEqualTo($fin)) {
                $hasta = $inicio->copy()->addMinutes($minutos);

                // Una hora que ya paso no es una opcion.
                if ($inicio->greaterThan($ahora)) {
                    $cuantos = $this->disponiblesPara($ambito, $inicio, $hasta, $solicitante)->count();

                    if ($cuantos > 0) {
                        $franjas->push([
                            'inicio'  => $inicio->copy(),
                            'fin'     => $hasta->copy(),
                            'cuantos' => $cuantos,
                        ]);
                    }
                }

                $inicio->addMinutes($minutos);
            }
        }

        return $franjas;
    }

    /** Este equipo admite asesorias porque hay alguien declarado (§10). */
    public function seAsesora(Asset|Area $ambito): bool
    {
        return $this->asesoresDe($ambito)->isNotEmpty();
    }

    /**
     * ¿Esta persona ya tiene algo suyo a esa hora?
     *
     * Cuenta lo que reservo —una maquina, otra asesoria—, no lo que otros
     * reservaron sobre ella: un colaborador que acompana a alguien esta ocupado
     * por esa via, y eso ya lo cubre `estaLibre`.
     */
    public function tieneAlgoALaMismaHora(User $persona, CarbonInterface $desde, CarbonInterface $hasta): bool
    {
        return Reservation::query()
            ->where('user_id', $persona->id)
            ->whereIn('status', Reservation::BLOQUEANTES)
            ->where('starts_at', '<', $hasta->copy()->utc())
            ->where('ends_at', '>', $desde->copy()->utc())
            ->exists();
    }
}
