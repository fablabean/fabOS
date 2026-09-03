<?php

namespace App\Services\Booking;

use App\Models\Asset;
use App\Models\Reservation;
use App\Models\Space;
use App\Models\User;
use App\Services\Money\ChargeService;
use App\Services\Money\QuoteService;
use App\Services\Notifications\NotificationService;
use App\Services\Staffing\CoverageService;
use App\Services\Staffing\ShiftService;
use Illuminate\Support\Facades\DB;

/**
 * La bandeja de solicitudes (§10).
 *
 * Una solicitud no bloquea el equipo: existe para que la coordinación decida.
 * Aprobarla es donde se juntan tres cosas que hasta ahora vivían separadas:
 *
 *  1. **La reserva se confirma** y recién ahí bloquea el equipo —y compromete
 *     saldo, si el cobro está encendido—.
 *  2. **Se le reserva el tiempo a quien acompaña**, para que no quede
 *     comprometido en dos sitios a la vez.
 *  3. **Si es fuera de la jornada, se le programa la jornada**, que pasa por el
 *     control de horas extras. Ese control es lo que evita que decir «sí» a un
 *     sábado se convierta, sin que nadie lo note, en la cuarta apertura del mes
 *     para la misma persona.
 *
 * Aprobar sin abrir la jornada sería prometer un acompañamiento que nadie está
 * obligado a cumplir.
 */
class ApprovalService
{
    public function __construct(
        private CoverageService $cobertura,
        private ShiftService $jornadas,
        private QuoteService $cotizador,
        private ChargeService $cobros,
        private NotificationService $avisos,
    ) {}

    /**
     * Aprueba una solicitud.
     *
     * @param  User|null  $acompanante  quién la atiende, si el equipo exige compañía
     *
     * @throws BookingException
     */
    public function aprobar(
        Reservation $solicitud,
        ?User $acompanante = null,
        ?User $quienAprueba = null,
        bool $abrirJornada = true,
    ): Reservation {
        if ($solicitud->status !== 'solicitada') {
            throw new BookingException(
                'Esta reserva está ' . mb_strtolower(Reservation::ESTADOS[$solicitud->status] ?? $solicitud->status)
                . ' y ya no está esperando decisión.'
            );
        }

        if ($solicitud->starts_at->isPast()) {
            throw new BookingException('Esa franja ya pasó. Pídele a la persona que vuelva a solicitarla.');
        }

        $equipo = $solicitud->reservable_type === Asset::class
            ? Asset::find($solicitud->reservable_id)
            : null;

        // Un espacio también se pide: fuera de la jornada del equipo la sala
        // no se confirma sola, porque abrirla cuesta horas extras.
        $espacio = $solicitud->reservable_type === Space::class
            ? Space::find($solicitud->reservable_id)
            : null;

        if (! $equipo && ! $espacio) {
            throw new BookingException('El recurso de esa solicitud ya no existe.');
        }

        if ($equipo && $equipo->status !== 'operativo') {
            throw new BookingException(
                $equipo->name . ' no está operativo: aprobar la solicitud prometería algo que no se puede cumplir.'
            );
        }

        $nombre = $equipo?->name ?? $espacio->name;

        // Quien va a atender tiene que estar libre: si tiene una asesoria o
        // tiempo apartado para un proyecto a esa hora, no esta.
        if ($acompanante && ! app(BookingService::class)->estaLibre(User::class, $acompanante->id, $solicitud->starts_at, $solicitud->ends_at)) {
            throw new BookingException(
                $acompanante->name . ' ya tiene algo a esa hora (una asesoría o tiempo apartado para un proyecto). Elige a otra persona.'
            );
        }

        return DB::transaction(function () use ($solicitud, $equipo, $espacio, $nombre, $acompanante, $quienAprueba, $abrirJornada) {
            // Si hay acompañante y no está en jornada a esa hora, se le programa.
            // El servicio de jornadas valida el tope de extras y lanza si se pasa.
            $yaEnJornada = $acompanante && $this->cobertura
                ->enJornada($solicitud->starts_at, $solicitud->ends_at)
                ->contains('id', $acompanante->id);

            if ($acompanante && $abrirJornada && ! $yaEnJornada) {
                $this->jornadas->programar(
                    $acompanante,
                    $solicitud->starts_at->copy(),
                    $solicitud->ends_at->copy(),
                    'Apertura por la solicitud #' . $solicitud->id . ' · ' . $nombre,
                    $quienAprueba,
                );
            }

            try {
                $solicitud->update([
                    'status'        => 'confirmada',
                    'supervisor_id' => $acompanante?->id ?? $solicitud->supervisor_id,
                    'status_reason' => 'Aprobada por ' . ($quienAprueba?->name ?? 'la coordinación'),
                ]);

                // Lo que colgaba de la solicitud -otros espacios, herramientas-
                // se confirma con ella: la actividad es una.
                Reservation::where('parent_reservation_id', $solicitud->id)
                    ->where('status', 'solicitada')
                    ->get()
                    ->each(fn (Reservation $hija) => $hija->update(['status' => 'confirmada']));
            } catch (\Illuminate\Database\QueryException $e) {
                // Mientras la solicitud esperaba, alguien tomó el espacio de
                // verdad. La base lo impide; aquí se dice.
                if (str_contains($e->getMessage(), 'sin_traslape')) {
                    throw new BookingException(
                        'Mientras esperaba, alguien más reservó ' . $nombre . ' a esa hora. Recházala con ese motivo.',
                    );
                }

                throw $e;
            }

            // El bloque de quien acompaña: su tiempo es un recurso más, y si no
            // se reserva la promesa de acompañamiento es falsa.
            if ($acompanante) {
                Reservation::create([
                    'parent_reservation_id' => $solicitud->id,
                    'reservable_type' => User::class,
                    'reservable_id'   => $acompanante->id,
                    'user_id'         => $solicitud->user_id,
                    'status'          => 'confirmada',
                    'mode'            => $solicitud->mode,
                    'starts_at'       => $solicitud->starts_at,
                    'ends_at'         => $solicitud->ends_at,
                    'purpose'         => 'Acompañamiento en ' . $nombre,
                ]);
            }

            // Un espacio no se cobra: solo las máquinas tienen tarifa.
            if ($equipo) {
                $minutos = (int) $solicitud->starts_at->diffInMinutes($solicitud->ends_at);
                $cotizacion = $this->cotizador->cotizar($solicitud->user, $equipo, $minutos, $acompanante !== null);

                $solicitud->update(['estimated_cost_minor' => $cotizacion->totalMenor]);
                $this->cobros->comprometer($solicitud->refresh(), $cotizacion);
            }

            $tz = config('fabos.lab.timezone');

            $this->avisos->enviar('reserva.confirmada', $solicitud->user, [
                'equipo'      => $nombre,
                'fecha'       => $solicitud->starts_at->timezone($tz)->format('d/m/Y'),
                'inicio'      => $solicitud->starts_at->timezone($tz)->format('H:i'),
                'fin'         => $solicitud->ends_at->timezone($tz)->format('H:i'),
                'acompanante' => $acompanante ? 'Te acompaña ' . $acompanante->name . '.' : '',
                'tolerancia'  => config('fabos.checkin.tolerancia'),
            ], $solicitud);

            return $solicitud->refresh();
        });
    }

    /**
     * Rechaza una solicitud, con motivo.
     *
     * El motivo no es cortesía: quien pidió algo y recibe un «no» sin
     * explicación vuelve a pedir lo mismo la semana siguiente.
     */
    public function rechazar(Reservation $solicitud, string $motivo, ?User $quienDecide = null): Reservation
    {
        if ($solicitud->status !== 'solicitada') {
            throw new BookingException('Esa reserva ya no está esperando decisión.');
        }

        $solicitud->update([
            'status'        => 'rechazada',
            'status_reason' => $motivo,
        ]);

        $this->avisos->enviar('reserva.rechazada', $solicitud->user, [
            'equipo' => $solicitud->reservable?->name ?? 'el recurso',
            'fecha'  => $solicitud->starts_at->timezone(config('fabos.lab.timezone'))->format('d/m/Y H:i'),
            'motivo' => $motivo,
        ], $solicitud);

        return $solicitud->refresh();
    }

    /** Las solicitudes que esperan decisión, primero las más próximas. */
    public function bandeja(): \Illuminate\Support\Collection
    {
        return Reservation::query()
            ->where('status', 'solicitada')
            ->where('ends_at', '>', now())
            ->with('user')
            ->orderBy('starts_at')
            ->get();
    }
}
