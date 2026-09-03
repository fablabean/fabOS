<?php

namespace App\Services\Booking;

use App\Models\Asset;
use App\Models\Reservation;
use App\Models\ReservationSupply;
use App\Models\Supply;
use App\Models\User;
use App\Services\Inventory\StockException;
use App\Services\Inventory\StockService;
use App\Services\Money\ChargeService;
use App\Services\Money\PricingService;
use App\Services\Money\QuoteService;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Carbon;

/**
 * Llegada y salida de una reserva (§10).
 *
 * El check-in no es un trámite: es lo que convierte una promesa en uso real.
 * Sin él no se puede distinguir una reserva aprovechada de una que dejó el
 * equipo bloqueado toda la tarde, ni liquidar consumo real contra lo estimado
 * cuando lleguen los FabCoins.
 */
class AttendanceService
{
    public function __construct(
        private QuoteService $cotizador,
        private ChargeService $cobros,
        private NotificationService $avisos,
        private StockService $existencias,
        private PricingService $precios,
        private WaitlistService $espera,
    ) {}

    /** Reserva activa de esta persona sobre este equipo, si la hay ahora. */
    public function reservaEnCurso(User $user, Asset $asset): ?Reservation
    {
        return Reservation::query()
            ->where('reservable_type', Asset::class)
            ->where('reservable_id', $asset->id)
            ->where('user_id', $user->id)
            ->whereIn('status', ['confirmada', 'en_curso'])
            ->where('ends_at', '>', now())
            ->orderBy('starts_at')
            ->first();
    }

    /**
     * @throws BookingException si todavía no es hora, si ya pasó la tolerancia
     *                          o si la reserva no está en estado de recibir a nadie
     */
    public function checkIn(Reservation $reserva): Reservation
    {
        if ($reserva->status === 'en_curso') {
            throw new BookingException('Ya habías registrado tu llegada.');
        }

        if ($reserva->status !== 'confirmada') {
            throw new BookingException(
                'Esta reserva está ' . (Reservation::ESTADOS[$reserva->status] ?? $reserva->status)
                . ' y no admite llegada.'
            );
        }

        $ahora = now();
        $abre  = $reserva->starts_at->copy()->subMinutes(config('fabos.checkin.antes'));

        /*
         * La tolerancia se cuenta desde que la reserva volvio a estar en pie.
         *
         * Una levantada a las nueve de la noche, de las que empezaban a las
         * cinco, nacia fuera de plazo: el primer intento de escanear la
         * marcaba otra vez como no presentada. Devolverla y que no sirva es
         * peor que no poder devolverla, porque parece que si se pudo.
         */
        $desdeCuando = $reserva->reinstated_at && $reserva->reinstated_at->greaterThan($reserva->starts_at)
            ? $reserva->reinstated_at
            : $reserva->starts_at;

        $cierra = $desdeCuando->copy()->addMinutes(config('fabos.checkin.tolerancia'));

        if ($ahora->lessThan($abre)) {
            throw new BookingException(
                'Todavía es temprano. Puedes registrar tu llegada desde las '
                . $abre->timezone(config('fabos.lab.timezone'))->format('H:i') . '.'
            );
        }

        if ($ahora->greaterThan($cierra)) {
            // Se marca la ausencia en el momento de descubrirla, no en un proceso
            // nocturno: así el equipo queda libre de inmediato.
            $this->marcarNoShow($reserva);

            throw new BookingException(
                'Pasaron más de ' . config('fabos.checkin.tolerancia')
                . ' minutos desde la hora de inicio, así que la reserva se liberó. '
                . 'Puedes volver a reservar si el equipo sigue libre.'
            );
        }

        $reserva->update([
            'status'        => 'en_curso',
            'checked_in_at' => $ahora,
        ]);

        return $reserva->refresh();
    }

    /**
     * Cierra la reserva y deja registrado el uso real.
     *
     * El material se declara aquí y no al reservar: nadie sabe de antemano
     * cuántos gramos va a gastar. Al declararlo, sale del inventario y entra en
     * la liquidación, de modo que existencia, cobro y costo cuentan lo mismo.
     *
     * @param  array<int,float>  $materiales  id del insumo => cantidad gastada
     *
     * @throws BookingException si la reserva no está en curso o falta material
     */
    public function checkOut(Reservation $reserva, array $materiales = []): Reservation
    {
        if ($reserva->status !== 'en_curso') {
            throw new BookingException('Esta reserva no está en curso.');
        }

        // Antes de cerrar nada: si no alcanza el material, es mejor saberlo con
        // la persona todavía delante del equipo.
        $this->registrarMaterial($reserva, $materiales);

        $reserva->update([
            'status'         => 'completada',
            'checked_out_at' => now(),
        ]);

        // El acompañamiento se cierra con la reserva: el colaborador queda libre
        // aunque el bloque reservado no haya terminado.
        Reservation::where('parent_reservation_id', $reserva->id)
            ->whereIn('status', ['confirmada', 'en_curso'])
            ->update(['status' => 'completada', 'checked_out_at' => now()]);

        $this->liquidar($reserva->refresh());

        return $reserva->refresh();
    }

    /** Minutos realmente usados; nulo si la reserva no se cerró. */
    public function minutosReales(Reservation $reserva): ?int
    {
        if (! $reserva->checked_in_at || ! $reserva->checked_out_at) {
            return null;
        }

        return (int) $reserva->checked_in_at->diffInMinutes($reserva->checked_out_at);
    }

    /**
     * Libera las reservas a las que nadie llegó. Pensado para el planificador,
     * como red de seguridad de lo que ya se marca al intentar el check-in tarde.
     */
    /**
     * Anota que llego a tiempo, desde el panel (§7, §10).
     *
     * Para cuando el escaner fallo, o para una asesoria que quien atendia
     * olvido validar. La llegada queda a la HORA DE INICIO: anotarla a la
     * hora en que alguien se acordo de pulsar diria que la persona llego
     * tarde, y no es verdad. Y lleva firma: es una correccion de una persona,
     * no un hecho que el sistema vio.
     *
     * @throws BookingException
     */
    public function llegoATiempo(Reservation $reserva, User $quien): Reservation
    {
        if ($reserva->checked_in_at) {
            throw new BookingException('Esta reserva ya tiene la llegada anotada.');
        }

        if (! in_array($reserva->status, ['confirmada', 'en_curso'], true)) {
            throw new BookingException(
                'Esta reserva está ' . mb_strtolower(Reservation::ESTADOS[$reserva->status] ?? $reserva->status)
                . ': levántala primero si hace falta.'
            );
        }

        if ($reserva->starts_at->isFuture()) {
            throw new BookingException('Todavía no ha empezado: no hay llegada que anotar.');
        }

        $reserva->update([
            'checked_in_at' => $reserva->starts_at,
            'status'        => $reserva->ends_at->isPast() ? 'completada' : 'en_curso',
            'status_reason' => 'Llegada a tiempo anotada por ' . $quien->name,
        ]);

        return $reserva->refresh();
    }

    /**
     * Cierra las asesorias que terminaron (§10).
     *
     * Una reserva de equipo se cierra al escanear la salida, y eso importa:
     * se cobra lo que de verdad se uso. Una asesoria no tiene salida que
     * escanear ni nada que cobrar por minutos: cuando pasa su hora de fin,
     * termino. Sin esto, la que se valido como «en curso» se quedaba en
     * curso para siempre.
     */
    public function cerrarAsesoriasTerminadas(?Carbon $ahora = null): int
    {
        return Reservation::query()
            ->where('mode', 'asesoria')
            ->where('status', 'en_curso')
            ->where('ends_at', '<=', ($ahora ?? now())->copy()->utc())
            ->update(['status' => 'completada']);
    }

    public function liberarAusencias(?Carbon $hasta = null): int
    {
        // De paso, lo que ya termino se cierra: es el mismo barrido de cada
        // cuarto de hora, y la asesoria no tiene otro momento en que cerrarse.
        $this->cerrarAsesoriasTerminadas($hasta);

        $limite = ($hasta ?? now())->copy()->subMinutes(config('fabos.checkin.tolerancia'));

        $pendientes = Reservation::query()
            ->where('status', 'confirmada')
            ->whereNull('checked_in_at')
            // Una produccion no se presenta: es el laboratorio corriendo su
            // propia maquina, y nadie escanea un QR para empezar a imprimir.
            // Este barrido las marcaba como «no se presento» a los quince
            // minutos y soltaba la maquina con la impresion a medias.
            ->where('is_production', false)
            // Una asesoria tampoco se escanea: la llegada la valida quien
            // atiende, desde su cuenta, y si nadie vino lo marca esa persona.
            // El barrido las daba por no presentadas a los veinte minutos
            // aunque la persona estuviera sentada al lado del asesor.
            ->where('mode', '<>', 'asesoria')
            ->where('starts_at', '<', $limite)
            ->get();

        $pendientes->each(fn (Reservation $r) => $this->marcarNoShow($r));

        return $pendientes->count();
    }

    /**
     * Descuenta del inventario el material declarado y congela su precio.
     *
     * @param  array<int,float>  $materiales
     *
     * @throws BookingException
     */
    private function registrarMaterial(Reservation $reserva, array $materiales): void
    {
        foreach ($materiales as $insumoId => $cantidad) {
            $cantidad = (float) $cantidad;

            if ($cantidad <= 0) {
                continue;
            }

            $insumo = Supply::find($insumoId);

            if (! $insumo) {
                continue;
            }

            try {
                $this->existencias->salida(
                    $insumo,
                    $cantidad,
                    'Reserva #' . $reserva->id,
                    $reserva,
                    $reserva->user,
                );
            } catch (StockException $e) {
                throw new BookingException($e->getMessage());
            }

            ReservationSupply::create([
                'reservation_id'   => $reserva->id,
                'supply_id'        => $insumo->id,
                'quantity'         => $cantidad,
                'unit_price_minor' => $this->precios->precioDe($insumo),
            ]);
        }
    }

    /**
     * Cobra lo realmente usado y devuelve la diferencia (§12).
     *
     * El consumo se recalcula con los minutos de reloj, no con los reservados:
     * quien pidió tres horas y usó una paga una. El montaje y el mínimo siguen
     * aplicando, porque el equipo se alistó igual. Al tiempo se le suma el
     * material declarado, que va a costo y sin el factor de la categoría.
     */
    private function liquidar(Reservation $reserva): void
    {
        if ($reserva->reservable_type !== Asset::class) {
            return;   // el bloque del acompañante no se cobra aparte
        }

        $minutos = $this->minutosReales($reserva) ?? 0;
        $equipo = Asset::find($reserva->reservable_id);

        $consumo = $equipo
            ? $this->cotizador->cotizar($reserva->user, $equipo, $minutos, $reserva->supervisor_id !== null)->totalMenor
            : 0;

        $consumo += (int) ReservationSupply::where('reservation_id', $reserva->id)
            ->get()
            ->sum(fn (ReservationSupply $m) => $m->totalMenor());

        $reserva->update(['actual_cost_minor' => $consumo]);

        $this->cobros->liquidar($reserva, $consumo);
    }

    private function marcarNoShow(Reservation $reserva): void
    {
        $reserva->update([
            'status'        => 'no_show',
            'status_reason' => 'Nadie llegó dentro de la tolerancia',
        ]);

        Reservation::where('parent_reservation_id', $reserva->id)
            ->whereIn('status', ['confirmada', 'en_curso'])
            ->update(['status' => 'no_show']);

        if ($reserva->user) {
            $equipo = $reserva->reservable_type === Asset::class
                ? Asset::find($reserva->reservable_id)
                : null;

            $this->avisos->enviar('reserva.no_show', $reserva->user, [
                'equipo'     => $equipo?->name ?? 'el equipo',
                'fecha'      => $reserva->starts_at->timezone(config('fabos.lab.timezone'))->format('d/m/Y H:i'),
                'tolerancia' => config('fabos.checkin.tolerancia'),
            ], $reserva);
        }

        // El equipo queda libre: quien esperaba esa franja quiere saberlo.
        $this->espera->alLiberarse($reserva);

        // No se cobra la ausencia: penalizarla es una decisión de política que
        // la coordinación todavía no ha tomado, y cobrar por defecto sería
        // inventarla. Queda anotada como pendiente en las reglas.
        $this->cobros->devolver($reserva, 'Nadie llegó dentro de la tolerancia');
    }
}
