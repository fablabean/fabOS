<?php

namespace App\Services\Booking;

use App\Models\Asset;
use App\Models\Reservation;
use App\Models\Space;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\Staffing\CoverageService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Otra opción para una solicitud que no se puede tal cual (§10).
 *
 * Desde la bandeja, la coordinación le escribe a quien pidió: «se puede, pero
 * solo una hora», «mejor el jueves». El sistema sugiere las franjas cercanas
 * que sí caben —el recurso libre, y quién del equipo puede atender— y en el
 * correo van como enlaces. La que la persona abra y confirme queda reservada
 * sola: la solicitud se mueve a esa hora y se aprueba con quien se eligió,
 * sin otra vuelta por la bandeja.
 */
class OtraOpcion
{
    /** Cuántas sugerencias se ofrecen como mucho. */
    public const MAXIMO = 8;

    public function __construct(
        private BookingService $reservas,
        private CoverageService $cobertura,
        private NotificationService $avisos,
    ) {}

    /**
     * Las franjas cercanas que sí caben: primero las que caen en jornada.
     *
     * @return list<array{desde:Carbon, hasta:Carbon, etiqueta:string, por_que:string, en_jornada:bool, libres:list<array{id:int,nombre:string}>}>
     */
    public function sugerencias(Reservation $s): array
    {
        $tz = config('fabos.lab.timezone');
        $desde = $s->starts_at->copy()->timezone($tz);
        $minutos = (int) $s->starts_at->diffInMinutes($s->ends_at);
        $candidatas = [];

        // La misma hora, más corta: «se puede, pero solo una hora».
        if ($minutos > 60) {
            $candidatas[] = [$desde->copy(), 60, 'Solo una hora'];
        }

        // El mismo día, antes o después.
        foreach ([-120, -60, 60, 120] as $corrimiento) {
            $candidatas[] = [$desde->copy()->addMinutes($corrimiento), $minutos, ($corrimiento < 0 ? 'Más temprano' : 'Más tarde') . ' el mismo día'];
        }

        // La misma hora, los días hábiles que siguen.
        $dia = $desde->copy();
        for ($n = 0; $n < 5;) {
            $dia->addDay();
            if ($dia->isSunday()) {
                continue;
            }
            $candidatas[] = [$dia->copy(), $minutos, 'Otro día, a la misma hora'];
            $n++;
        }

        $sugerencias = [];

        foreach ($candidatas as [$inicio, $duracion, $porQue]) {
            $fin = $inicio->copy()->addMinutes($duracion);

            if ($inicio->lt(now()) || $inicio->hour < 7 || $fin->gt($inicio->copy()->setTime(21, 0))) {
                continue;
            }

            if (\App\Support\BloqueoDeReservas::cubre($inicio, $fin) || ! $this->recursoLibre($s, $inicio, $fin)) {
                continue;
            }

            $libres = $this->quienesPuedenAtender($s)
                ->filter(fn (User $u) => $this->reservas->personaLibre($u, $inicio, $fin))
                ->map(fn (User $u) => ['id' => $u->id, 'nombre' => $u->name])
                ->values()->all();

            $enJornada = $this->cobertura->hayCobertura($inicio, $fin, incluirRemota: $s->reservable instanceof Space && $s->reservable->type === 'virtual');

            $sugerencias[] = [
                'desde'      => $inicio,
                'hasta'      => $fin,
                'etiqueta'   => self::comoSeDice($inicio, $fin),
                'por_que'    => $porQue,
                'en_jornada' => $enJornada,
                'libres'     => $libres,
            ];
        }

        // Lo que se confirma sin abrir jornada y tiene quien atienda, primero;
        // y entre iguales, lo más cerca de lo que pidió.
        usort($sugerencias, fn ($a, $b) => [! $a['en_jornada'], empty($a['libres']), abs($a['desde']->diffInMinutes($desde))]
            <=> [! $b['en_jornada'], empty($b['libres']), abs($b['desde']->diffInMinutes($desde))]);

        return array_slice($sugerencias, 0, self::MAXIMO);
    }

    /** «jueves 19 de noviembre, de 12:00 a 13:00» */
    public static function comoSeDice(CarbonInterface $desde, CarbonInterface $hasta): string
    {
        $tz = config('fabos.lab.timezone');
        $d = $desde->copy()->timezone($tz)->locale('es');

        return $d->isoFormat('dddd D [de] MMMM') . ', de ' . $d->format('H:i') . ' a ' . $hasta->copy()->timezone($tz)->format('H:i');
    }

    /**
     * Le escribe con las opciones elegidas. La solicitud sigue pedida: no se
     * confirma nada hasta que la persona elija.
     *
     * @param  list<array{desde:string, hasta:string}>  $opciones
     *
     * @throws BookingException
     */
    public function proponer(Reservation $s, array $opciones, ?string $mensaje, ?User $atiende, User $quien): Reservation
    {
        if ($s->status !== 'solicitada') {
            throw new BookingException('Esta solicitud ya no está esperando decisión.');
        }

        if (! $s->user?->email) {
            throw new BookingException('Quien la pidió no tiene correo: no hay a dónde escribirle.');
        }

        $opciones = collect($opciones)->map(fn ($o) => [
            'desde' => Carbon::parse($o['desde'])->utc()->toIso8601String(),
            'hasta' => Carbon::parse($o['hasta'])->utc()->toIso8601String(),
        ])->values()->all();

        if ($opciones === [] && blank($mensaje)) {
            throw new BookingException('Escribe algo o marca al menos una opción.');
        }

        $s->update([
            'propuesta' => [
                'por'       => $quien->id,
                'atiende'   => $atiende?->id,
                'mensaje'   => trim((string) $mensaje),
                'opciones'  => $opciones,
                'enviada'   => now()->utc()->toIso8601String(),
            ],
            'status_reason' => 'Le propusimos ' . (count($opciones) ?: 'otra')
                . (count($opciones) === 1 ? ' opción' : (count($opciones) ? ' opciones' : ' opción')) . ' el '
                . now(config('fabos.lab.timezone'))->format('d/m H:i') . ' (' . $quien->name . '). Esperando su respuesta.',
        ]);

        $lineas = collect($opciones)->map(fn ($o, $i) => '• ' . self::comoSeDice(Carbon::parse($o['desde']), Carbon::parse($o['hasta']))
            . "\n  " . $this->enlace($s, $i))->implode("\n\n");

        $this->avisos->enviar('reserva.otra_opcion', $s->user, [
            'recurso'  => $s->nombreDelRecurso(),
            'pedida'   => self::comoSeDice($s->starts_at, $s->ends_at),
            'mensaje'  => filled(trim((string) $mensaje)) ? "\n\n" . trim((string) $mensaje) : '',
            'opciones' => $lineas ?: '(Sin opciones: responde este correo y lo acordamos.)',
        ], $s);

        return $s->refresh();
    }

    public function enlace(Reservation $s, int $opcion): string
    {
        $o = $s->propuesta['opciones'][$opcion] ?? null;

        return URL::temporarySignedRoute('reservas.otra-opcion', $o ? Carbon::parse($o['desde']) : now()->addDays(7), [
            'reservation' => $s->id,
            'opcion'      => $opcion,
        ]);
    }

    /**
     * La persona eligió: la solicitud se mueve a esa franja —con lo que
     * cuelga de ella— y se aprueba con quien se había propuesto, o con quien
     * esté libre si esa persona ya no lo está.
     *
     * @throws BookingException
     */
    public function tomar(Reservation $s, int $opcion): Reservation
    {
        $o = $s->propuesta['opciones'][$opcion] ?? null;

        if (! $o) {
            throw new BookingException('Esa opción ya no existe.');
        }

        if ($s->status !== 'solicitada') {
            throw new BookingException($s->status === 'confirmada'
                ? 'Tu reserva ya quedó confirmada: ' . self::comoSeDice($s->starts_at, $s->ends_at) . '.'
                : 'Esta solicitud ya no está abierta. Si todavía la necesitas, pídela de nuevo.');
        }

        $desde = Carbon::parse($o['desde']);
        $hasta = Carbon::parse($o['hasta']);

        if ($desde->isPast()) {
            throw new BookingException('Esa opción ya pasó. Responde el correo y buscamos otra.');
        }

        if (! $this->recursoLibre($s, $desde, $hasta)) {
            throw new BookingException('Alguien tomó esa franja mientras tanto. Responde el correo y buscamos otra.');
        }

        $propuesto = User::find($s->propuesta['atiende'] ?? 0);
        $quien = User::find($s->propuesta['por'] ?? 0);

        return DB::transaction(function () use ($s, $desde, $hasta, $opcion, $propuesto, $quien) {
            $corrimiento = (int) $s->starts_at->diffInSeconds($desde, false);

            // Lo que cuelga de la solicitud —las herramientas, la otra sala—
            // se mueve con ella.
            foreach (Reservation::where('parent_reservation_id', $s->id)->get() as $hija) {
                $hija->update([
                    'starts_at' => $hija->starts_at->copy()->addSeconds($corrimiento),
                    'ends_at'   => $hija->ends_at->copy()->addSeconds($corrimiento),
                ]);
            }

            $s->update([
                'starts_at' => $desde,
                'ends_at'   => $hasta,
                'propuesta' => array_merge($s->propuesta, ['tomada' => $opcion, 'tomada_el' => now()->utc()->toIso8601String()]),
            ]);

            $atiende = $propuesto && $this->reservas->personaLibre($propuesto, $desde, $hasta)
                ? $propuesto
                : $this->quienesPuedenAtender($s)->first(fn (User $u) => $this->reservas->personaLibre($u, $desde, $hasta));

            return app(ApprovalService::class)->aprobar($s->fresh(), $atiende, $quien);
        });
    }

    /** El recurso no tiene otra reserva firme en esa franja (los recorridos no cierran una sala). */
    private function recursoLibre(Reservation $s, CarbonInterface $desde, CarbonInterface $hasta): bool
    {
        return ! Reservation::query()
            ->where('reservable_type', $s->reservable_type)
            ->where('reservable_id', $s->reservable_id)
            ->whereKeyNot($s->id)
            ->whereIn('status', Reservation::BLOQUEANTES)
            ->where('mode', '!=', Reservation::MODO_RECORRIDO)
            ->where('starts_at', '<', $hasta->copy()->utc())
            ->where('ends_at', '>', $desde->copy()->utc())
            ->exists();
    }

    /** @return Collection<int,User> */
    public function quienesPuedenAtender(Reservation $s): Collection
    {
        return $s->reservable instanceof Asset
            ? $this->cobertura->certificadosPara($s->reservable)->values()
            : User::role(User::rolesDelEquipo())->where('status', 'activo')->orderBy('name')->get();
    }
}
