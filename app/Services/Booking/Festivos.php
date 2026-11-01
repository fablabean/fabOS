<?php

namespace App\Services\Booking;

use App\Models\Bloqueo;
use App\Models\Reservation;
use App\Services\Notifications\NotificationService;
use App\Support\BloqueoDeReservas;
use App\Support\DiasHabiles;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Los festivos de Colombia cierran las reservas (App\Support\DiasHabiles).
 *
 * Cada festivo se vuelve un bloqueo de día completo, como el que pondría
 * alguien a mano: sale rayado en la semana, se avisa en el sitio unos días
 * antes, su mensaje se puede corregir, y si un festivo sí se abre se levanta
 * desde «Bloqueo y horario». Por eso se crean con anticipación y no se
 * calculan al vuelo: un festivo que no está en la tabla no se puede levantar.
 */
class Festivos
{
    /** Cuántos días antes empieza el aviso en el sitio. */
    public const AVISAR_DIAS_ANTES = 3;

    public const POR = 'fabOS (festivo)';

    private const MOTIVO_CANCELACION = 'Día festivo: el laboratorio no abre';

    public function __construct(
        private BookingService $reservas,
        private NotificationService $avisos,
    ) {}

    /**
     * Crea los bloqueos de los festivos de los próximos `$dias` días que aún
     * no tienen el suyo. Uno levantado cuenta como creado: no se repone.
     *
     * @return Collection<int,Bloqueo> los que creó
     */
    public function bloquear(int $dias = 60): Collection
    {
        $tz = config('fabos.lab.timezone');
        $hoy = now($tz)->startOfDay();
        $festivos = DiasHabiles::festivosEntre($hoy, $hoy->copy()->addDays($dias));

        $ya = Bloqueo::query()->whereIn('festivo', array_keys($festivos))->pluck('festivo')
            ->map(fn ($f) => $f->toDateString())->all();

        return collect($festivos)
            ->except($ya)
            ->map(function (string $nombre, string $fecha) use ($tz) {
                $dia = Carbon::parse($fecha, $tz);

                $b = BloqueoDeReservas::programar(
                    self::motivo($nombre),
                    $dia,
                    $dia->copy()->addDay(),
                    $dia->copy()->subDays(self::AVISAR_DIAS_ANTES),
                    self::POR,
                    diasCompletos: true,
                );
                $b->update(['festivo' => $fecha]);

                return $b;
            })
            ->values();
    }

    public static function motivo(string $nombre): string
    {
        return "Es festivo ({$nombre}) y el laboratorio no abre. Los demás días se reserva normal.";
    }

    /**
     * Las reservas en pie que caen en un festivo con su bloqueo vigente.
     *
     * Producir y el tiempo apartado de un proyecto quedan fuera: los
     * proyectos siguen normales también con el laboratorio cerrado a las
     * reservas.
     *
     * @return Collection<int,Reservation>
     */
    public function reservasAfectadas(): Collection
    {
        return Bloqueo::query()->vigentes()->whereNotNull('festivo')->get()
            ->flatMap(fn (Bloqueo $b) => Reservation::query()
                ->whereNull('parent_reservation_id')
                ->whereIn('status', ['solicitada', 'confirmada'])
                ->where(fn ($q) => $q->whereNull('is_production')->orWhere('is_production', false))
                ->where(fn ($q) => $q->whereNull('mode')->orWhere('mode', '!=', 'proyecto'))
                ->where('starts_at', '<', $b->hasta)
                ->where('ends_at', '>', max($b->desde, now()))
                ->with(['user', 'reservable'])
                ->orderBy('starts_at')
                ->get())
            ->unique('id')
            ->values();
    }

    /**
     * Cancela una reserva que cae en festivo y le escribe a quien la hizo
     * para que reserve otro día. El correo sale una sola vez por reserva.
     */
    public function cancelarYAvisar(Reservation $reserva): void
    {
        $this->reservas->cancelar($reserva, self::MOTIVO_CANCELACION);

        if (! $reserva->user) {
            return;
        }

        $tz = config('fabos.lab.timezone');

        $this->avisos->enviarUnaVez('reserva.cancelada_por_festivo', $reserva->user, $reserva, [
            'equipo'  => $reserva->nombreDelRecurso(),
            'fecha'   => $reserva->starts_at->copy()->timezone($tz)->locale('es')->isoFormat('dddd D [de] MMMM'),
            'inicio'  => $reserva->starts_at->copy()->timezone($tz)->format('H:i'),
            'fin'     => $reserva->ends_at->copy()->timezone($tz)->format('H:i'),
            'festivo' => DiasHabiles::festivo($reserva->starts_at) ?? DiasHabiles::festivo($reserva->ends_at) ?? 'festivo',
            'enlace'  => route('reservas.index'),
        ]);
    }
}
