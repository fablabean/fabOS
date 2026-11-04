<?php

namespace App\Services\Projects;

use App\Models\Project;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\Staffing\CoverageService;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * La cola de revisión de las solicitudes de proyecto (§11).
 *
 * Entraban diez proyectos el mismo día y todos se prometían igual de pronto:
 * la capacidad del equipo para responder se copaba y nadie sabía cuándo le
 * tocaba a cada uno. Ahora cada solicitud toma una franja de revisión —una
 * hora, por defecto— en la agenda de quien la recibe, en la siguiente que
 * tenga libre. Responder toma diez minutos; la hora es la holgura para mirar
 * si cabe, con qué máquinas y cuánto tomaría, entre lo demás del día.
 *
 * La franja es una reserva de la persona, como un bloque de proyecto: sale en
 * su calendario, y lo que pregunta «¿está libre?» —asesorías, acompañamientos—
 * la respeta. Y le da a quien pidió una fecha honesta: «tu proyecto se revisa
 * el jueves», no «pronto».
 */
class RevisionDeSolicitudes
{
    /** Así se reconoce la franja entre los bloques de proyecto. */
    public const MOTIVO = 'Revisión de la solicitud';

    /** Cada cuánto se prueba una franja: las revisiones empiezan en punto o y media. */
    private const PASO_MINUTOS = 30;

    public function __construct(
        private BookingService $reservas,
        private CoverageService $jornadas,
    ) {}

    public static function minutos(): int
    {
        return max(15, (int) config('fabos.proyectos.minutos_de_revision', 60));
    }

    /**
     * Aparta la revisión en la siguiente franja libre de quien lleva el
     * proyecto. Nula si no tiene responsable o no hay franja en la ventana:
     * entonces la solicitud espera como antes, sin fecha prometida.
     */
    public function apartar(Project $proyecto): ?Reservation
    {
        $lider = $proyecto->lead;

        if (! $lider) {
            return null;
        }

        if ($ya = self::de($proyecto)) {
            return $ya;
        }

        for ($intento = 0; $intento < 3; $intento++) {
            $franja = $this->siguienteFranja($lider);

            if (! $franja) {
                return null;
            }

            try {
                return Reservation::create([
                    'reservable_type' => User::class,
                    'reservable_id'   => $lider->id,
                    'user_id'         => $lider->id,
                    'project_id'      => $proyecto->id,
                    'mode'            => TiempoDeProyecto::MODO,
                    'status'          => 'confirmada',
                    'starts_at'       => $franja[0],
                    'ends_at'         => $franja[1],
                    'purpose'         => self::MOTIVO . ' · ' . $proyecto->code . ' · ' . $proyecto->name,
                    'status_reason'   => 'Apartada sola al llegar la solicitud',
                ]);
            } catch (QueryException $e) {
                // Otra solicitud tomó esa misma franja entre buscar y grabar:
                // se busca la siguiente.
                if (! str_contains($e->getMessage(), 'sin_traslape')) {
                    throw $e;
                }
            }
        }

        return null;
    }

    /**
     * Pone el turno al día con lo que le pasó al proyecto.
     *
     * Ya respondido —con propuesta, o movido más allá de idea— o cerrado o en
     * pausa: la hora que quedaba por delante se suelta y vuelve a estar libre.
     * Con otro responsable: se suelta la del anterior y se busca la siguiente
     * del nuevo, que es quien la va a revisar.
     */
    public function ajustar(Project $proyecto): void
    {
        $franja = self::de($proyecto);

        if (! $franja) {
            return;
        }

        $sigueEnCola = $proyecto->stage === 'idea'
            && ! $proyecto->proposal_sent_at
            && $proyecto->status === 'activo';

        if ($sigueEnCola && $franja->reservable_id === $proyecto->lead_id) {
            return;
        }

        $this->soltar($franja, $sigueEnCola ? 'Pasó a otro responsable' : 'Ya no hace falta: se respondió o se cerró');

        if ($sigueEnCola) {
            $this->apartar($proyecto->fresh());
        }
    }

    /**
     * Lo que no ha empezado se cancela; lo que está corriendo se corta en
     * este momento. Lo que ya pasó queda: es historia de cuándo se revisó.
     */
    private function soltar(Reservation $franja, string $porQue): void
    {
        if ($franja->ends_at->lte(now())) {
            return;
        }

        if ($franja->starts_at->gt(now())) {
            $franja->update(['status' => 'cancelada', 'status_reason' => $porQue]);

            return;
        }

        $franja->update(['ends_at' => now(), 'status_reason' => $porQue]);
    }

    /** La franja de revisión que tiene el proyecto, si la tiene. */
    public static function de(Project $proyecto): ?Reservation
    {
        return Reservation::where('project_id', $proyecto->id)
            ->where('mode', TiempoDeProyecto::MODO)
            ->whereNull('project_task_id')
            ->where('purpose', 'like', self::MOTIVO . '%')
            ->whereIn('status', Reservation::BLOQUEANTES)
            ->where('ends_at', '>', now())
            ->orderBy('starts_at')
            ->first();
    }

    /**
     * La primera franja, desde ahora, en que la persona está en jornada
     * —presencial o remota: revisar se hace desde cualquier parte— y libre.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    public function siguienteFranja(User $persona, ?CarbonInterface $desde = null): ?array
    {
        $tz = config('fabos.lab.timezone');
        $minutos = self::minutos();
        $dias = max(1, (int) config('fabos.proyectos.dias_para_buscar_revision', 21));

        $inicio = Carbon::parse($desde ?? now(), $tz)->setTimezone($tz)->second(0);
        $resto = $inicio->minute % self::PASO_MINUTOS;

        if ($resto) {
            $inicio->addMinutes(self::PASO_MINUTOS - $resto);
        }

        $tope = $inicio->copy()->addDays($dias);

        for ($d = $inicio->copy(); $d->lt($tope); $d->addMinutes(self::PASO_MINUTOS)) {
            $h = $d->copy()->addMinutes($minutos);

            // De noche no hay jornada: se salta al día siguiente sin preguntar
            // treinta y dos veces.
            if ($d->hour >= 21) {
                $d->addDay()->setTime(5, 30);

                continue;
            }

            if (! $this->jornadas->enJornada($d, $h, incluirRemota: true)->contains('id', $persona->id)) {
                continue;
            }

            if ($this->reservas->personaLibre($persona, $d, $h)) {
                return [$d->copy(), $h];
            }
        }

        return null;
    }

    /** «hoy», «mañana» o «el jueves 6 de noviembre», para decírselo a quien pidió. */
    public static function cuandoSeDice(Reservation $franja): string
    {
        $tz = config('fabos.lab.timezone');
        $dia = $franja->starts_at->copy()->setTimezone($tz)->locale('es');
        $hoy = now($tz)->startOfDay();

        return match (true) {
            $dia->isSameDay($hoy)            => 'hoy',
            $dia->isSameDay($hoy->copy()->addDay()) => 'mañana',
            default                          => 'el ' . $dia->isoFormat('dddd D [de] MMMM'),
        };
    }
}
