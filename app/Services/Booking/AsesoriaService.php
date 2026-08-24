<?php

namespace App\Services\Booking;

use App\Models\Asset;
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
    public function asesoresDe(Asset $asset): Collection
    {
        return $asset->advisors()->where('users.status', 'activo')->get();
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
        Asset $asset,
        CarbonInterface $desde,
        CarbonInterface $hasta,
        ?User $solicitante = null,
    ): Collection {
        $declarados = $this->asesoresDe($asset);

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
        Asset $asset,
        CarbonInterface $desde,
        CarbonInterface $hasta,
        ?User $solicitante = null,
    ): ?User {
        $candidatos = $this->disponiblesPara($asset, $desde, $hasta, $solicitante);

        if ($candidatos->isEmpty()) {
            return null;
        }

        $responsables = $candidatos->filter(
            fn (User $u) => (bool) $u->pivot?->es_responsable,
        );

        if ($responsables->isNotEmpty()) {
            $candidatos = $responsables->values();
        }

        $historial = $this->historialDe($asset, $candidatos->pluck('id')->all());

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
        Asset $asset,
        CarbonInterface $desde,
        CarbonInterface $hasta,
        ?string $motivo = null,
    ): ?Reservation {
        return DB::transaction(function () use ($solicitante, $asset, $desde, $hasta, $motivo) {
            $asesor = $this->elegir($asset, $desde, $hasta, $solicitante);

            if (! $asesor) {
                return null;
            }

            return Reservation::create([
                'reservable_type'   => User::class,
                'reservable_id'     => $asesor->id,
                'user_id'           => $solicitante->id,
                'advisory_asset_id' => $asset->id,
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
    public function historialDe(Asset $asset, array $userIds = []): array
    {
        return Reservation::query()
            ->where('mode', 'asesoria')
            ->where('advisory_asset_id', $asset->id)
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
        Asset $asset,
        ?User $solicitante = null,
        int $dias = 7,
        ?int $minutos = null,
    ): Collection {
        if ($this->asesoresDe($asset)->isEmpty()) {
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
                    $cuantos = $this->disponiblesPara($asset, $inicio, $hasta, $solicitante)->count();

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
    public function seAsesora(Asset $asset): bool
    {
        return $this->asesoresDe($asset)->isNotEmpty();
    }
}
