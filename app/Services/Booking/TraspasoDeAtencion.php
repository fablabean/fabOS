<?php

namespace App\Services\Booking;

use App\Models\Area;
use App\Models\Asset;
use App\Models\AssetAdvisor;
use App\Models\Reservation;
use App\Models\ReservationTransfer;
use App\Models\Space;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pasarle a otra persona del equipo lo que a uno le toca atender (§10).
 *
 * Una asesoría o un acompañamiento quedan a nombre de alguien concreto. Si
 * ese día no puede, hasta ahora tenía que escribirle a la coordinación para
 * que lo reasignara a mano. Con esto se lo propone directamente a un
 * compañero desde su cuenta.
 *
 * La regla que lo sostiene: **nadie recibe una atención sin haber dicho que
 * sí.** Proponer no cambia nada; la reserva sigue a nombre de quien la tenía
 * hasta que la otra persona acepta. Si rechaza, o si nadie responde, quien la
 * tenía la sigue teniendo —y lo sabe, porque la ve en su cuenta con la
 * propuesta pendiente al lado—.
 *
 * Tres cosas se pueden pasar, y las tres se atienden de manera distinta:
 *
 *  · una **asesoría**: la reserva es del tiempo de quien asesora, y pasarla es
 *    cambiar de quién es ese tiempo;
 *  · un **acompañamiento en una máquina**: la reserva es de la máquina, con un
 *    supervisor y un bloque aparte que reserva su tiempo; se cambian los dos;
 *  · un **acompañamiento en un espacio**: quien acompaña está en una lista, y
 *    pasarla es cambiar un nombre por otro en esa lista.
 */
class TraspasoDeAtencion
{
    public function __construct(
        private BookingService $reservas,
        private NotificationService $avisos,
    ) {}

    /**
     * A quién se le podría pasar: el equipo, menos uno mismo y menos quien la
     * pidió.
     *
     * Para una asesoría, primero quienes están declarados para ese equipo o
     * esa área —son los que la coordinación eligió para eso—, pero no solo
     * ellos: quien sabe de la máquina sin estar declarado también puede
     * ayudar un día.
     *
     * @return Collection<int,User>  cada uno con `sugerido` en true si asesora eso
     */
    public function candidatos(Reservation $reserva, User $de): Collection
    {
        $sugeridos = $this->declaradosPara($reserva);

        return User::role(User::ROLES_BACKOFFICE)
            ->where('status', 'activo')
            ->where('id', '!=', $de->id)
            ->where('id', '!=', $reserva->user_id)
            ->orderBy('name')
            ->get()
            ->each(fn (User $u) => $u->setAttribute('sugerido', $sugeridos->contains($u->id)))
            ->sortBy([['sugerido', 'desc'], ['name', 'asc']])
            ->values();
    }

    /**
     * Le propone a alguien quedarse con esta atención.
     *
     * @throws BookingException
     */
    public function proponer(Reservation $reserva, User $de, User $a, ?string $nota = null): ReservationTransfer
    {
        $this->exigirQueSePuedaPasar($reserva, $de);
        $this->exigirQuePuedaRecibirla($reserva, $de, $a);

        $traspaso = ReservationTransfer::create([
            'reservation_id' => $reserva->id,
            'from_user_id'   => $de->id,
            'to_user_id'     => $a->id,
            'status'         => ReservationTransfer::PENDIENTE,
            'note'           => $nota ?: null,
        ]);

        $this->avisos->enviar('traspaso.propuesto', $a, $this->datosDe($reserva) + [
            'de'   => $de->name,
            'nota' => $nota ?: 'No dijo por qué.',
        ], $traspaso);

        return $traspaso;
    }

    /**
     * Quien la recibe dice que sí, y recién ahí cambia de manos.
     *
     * @throws BookingException
     */
    public function aceptar(ReservationTransfer $traspaso, User $quien): ReservationTransfer
    {
        $this->exigirQueEsteEsperandoA($traspaso, $quien);

        $reserva = $traspaso->reservation;
        $de = $traspaso->from;

        // Lo que valía al proponerla tiene que seguir valiendo ahora: entre
        // una cosa y otra pudieron cancelarla, o a quien acepta le pudo
        // entrar otra asesoría a la misma hora.
        $this->exigirQueSePuedaPasar($reserva, $de, contarLaPendiente: false);
        $this->exigirQuePuedaRecibirla($reserva, $de, $quien);

        try {
            DB::transaction(function () use ($reserva, $de, $quien, $traspaso) {
                $this->cambiarDeManos($reserva, $de, $quien);

                $traspaso->update([
                    'status'     => ReservationTransfer::ACEPTADO,
                    'decided_at' => now(),
                ]);
            });
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'reservations_sin_traslape')) {
                throw new BookingException('Ya tienes otra cosa reservada a esa hora: no puedes quedarte con esta.');
            }

            throw $e;
        }

        $datos = $this->datosDe($reserva);

        $this->avisos->enviar('traspaso.aceptado', $de, $datos + ['a' => $quien->name], $traspaso);

        // Y a quien la pidió: le dijeron un nombre, y ahora es otro. Que se
        // entere por el sistema y no al llegar y encontrarse con un extraño.
        if ($reserva->user) {
            $this->avisos->enviar('atencion.reasignada', $reserva->user, $datos + [
                'antes' => $de->name,
                'ahora' => $quien->name,
            ], $reserva);
        }

        return $traspaso->refresh();
    }

    /**
     * @throws BookingException
     */
    public function rechazar(ReservationTransfer $traspaso, User $quien, ?string $motivo = null): ReservationTransfer
    {
        $this->exigirQueEsteEsperandoA($traspaso, $quien);

        $traspaso->update([
            'status'     => ReservationTransfer::RECHAZADO,
            'answer'     => $motivo ?: null,
            'decided_at' => now(),
        ]);

        $this->avisos->enviar('traspaso.rechazado', $traspaso->from, $this->datosDe($traspaso->reservation) + [
            'a'      => $quien->name,
            'motivo' => $motivo ?: 'No dijo por qué.',
        ], $traspaso);

        return $traspaso->refresh();
    }

    /**
     * Quien la propuso se arrepiente antes de que respondan.
     *
     * @throws BookingException
     */
    public function retirar(ReservationTransfer $traspaso, User $quien): ReservationTransfer
    {
        if ($traspaso->from_user_id !== $quien->id) {
            throw new BookingException('Esta propuesta no es tuya.');
        }

        if (! $traspaso->estaPendiente()) {
            throw new BookingException('Esta propuesta ya fue ' . mb_strtolower(ReservationTransfer::ESTADOS[$traspaso->status] ?? $traspaso->status) . '.');
        }

        $traspaso->update([
            'status'     => ReservationTransfer::RETIRADO,
            'decided_at' => now(),
        ]);

        return $traspaso->refresh();
    }

    // ------------------------------------------------------------ las reglas

    /**
     * @throws BookingException
     */
    private function exigirQueSePuedaPasar(Reservation $reserva, User $de, bool $contarLaPendiente = true): void
    {
        if (! $reserva->laAtiende($de)) {
            throw new BookingException('Esta atención no está a tu nombre.');
        }

        if ($reserva->status !== 'confirmada') {
            throw new BookingException(
                'Esta atención está ' . mb_strtolower(Reservation::ESTADOS[$reserva->status] ?? $reserva->status)
                . ' y ya no se puede pasar.'
            );
        }

        // Hasta que termine, no hasta que empiece: en una asesoria de VR la
        // persona llega preguntando por diseño, y quien la recibio tiene que
        // poder pasarsela ahi mismo a quien sabe de eso.
        if ($reserva->ends_at->isPast()) {
            throw new BookingException('Esa atención ya terminó: ya no se puede pasar.');
        }

        if ($contarLaPendiente && $reserva->traspasoPendiente()->exists()) {
            throw new BookingException('Ya hay una propuesta esperando respuesta para esta atención.');
        }
    }

    /**
     * @throws BookingException
     */
    private function exigirQuePuedaRecibirla(Reservation $reserva, User $de, User $a): void
    {
        if ($a->id === $de->id) {
            throw new BookingException('No puedes pasártela a ti mismo.');
        }

        if ($a->id === $reserva->user_id) {
            throw new BookingException('Nadie se atiende a sí mismo: elige a otra persona.');
        }

        if ($a->status !== 'activo' || ! $a->hasAnyRole(User::ROLES_BACKOFFICE)) {
            throw new BookingException($a->name . ' no es del equipo del laboratorio.');
        }

        // Lo que queda de la atencion: si ya empezo, desde ahora hasta que termine.
        $desde = $reserva->starts_at->isPast() ? now() : $reserva->starts_at;
        $ocupado = $this->reservas->porQueNoEstaLibre($a, $desde, $reserva->ends_at);

        if ($ocupado) {
            throw new BookingException($ocupado . ' Elige a otra persona.');
        }
    }

    /**
     * @throws BookingException
     */
    private function exigirQueEsteEsperandoA(ReservationTransfer $traspaso, User $quien): void
    {
        if ($traspaso->to_user_id !== $quien->id) {
            throw new BookingException('Esta propuesta no es para ti.');
        }

        if (! $traspaso->estaPendiente()) {
            throw new BookingException('Esta propuesta ya fue ' . mb_strtolower(ReservationTransfer::ESTADOS[$traspaso->status] ?? $traspaso->status) . '.');
        }
    }

    /** El cambio de manos, según qué sea. */
    private function cambiarDeManos(Reservation $reserva, User $de, User $a): void
    {
        if ($reserva->esAtencionPersonal()) {
            $reserva->update([
                'reservable_id' => $a->id,
                'status_reason' => 'Pasada por ' . $de->name . ' a ' . $a->name,
            ]);

            return;
        }

        if ($reserva->reservable_type === Asset::class) {
            $reserva->update(['supervisor_id' => $a->id]);

            // El bloque que reservaba el tiempo del supervisor: ahora es el
            // tiempo de la otra persona. Si no se moviera, la primera seguiría
            // ocupada por algo que ya no atiende, y la segunda libre para algo
            // que ya no puede.
            Reservation::where('parent_reservation_id', $reserva->id)
                ->where('reservable_type', User::class)
                ->where('reservable_id', $de->id)
                ->whereIn('status', Reservation::BLOQUEANTES)
                ->update(['reservable_id' => $a->id, 'updated_at' => now()]);

            return;
        }

        if ($reserva->reservable_type === Space::class) {
            $reserva->companions()->detach($de->id);
            $reserva->companions()->attach($a->id);
            $reserva->unsetRelation('companions');

            return;
        }

        throw new BookingException('Esto no es una atención que se pueda pasar.');
    }

    /** @return Collection<int,int> ids de quienes están declarados para lo que trata la asesoría */
    private function declaradosPara(Reservation $reserva): Collection
    {
        if (! $reserva->esAtencionPersonal()) {
            return collect();
        }

        if ($reserva->advisory_asset_id) {
            return AssetAdvisor::where('asset_id', $reserva->advisory_asset_id)->pluck('user_id');
        }

        if ($reserva->advisory_area_id) {
            return AssetAdvisor::whereIn(
                'asset_id',
                Asset::where('area_id', $reserva->advisory_area_id)->select('id'),
            )->pluck('user_id');
        }

        return collect();
    }

    /** Lo que todos los avisos del traspaso necesitan decir. */
    private function datosDe(Reservation $reserva): array
    {
        $tz = config('fabos.lab.timezone');

        return [
            'que'        => $reserva->queAtiende(),
            'area'       => $reserva->areaDeLoQueAtiende()?->name ?? '',
            'fecha'      => $reserva->starts_at->timezone($tz)->format('d/m/Y'),
            'inicio'     => $reserva->starts_at->timezone($tz)->format('H:i'),
            'fin'        => $reserva->ends_at->timezone($tz)->format('H:i'),
            'para_quien' => $reserva->user?->name ?? 'alguien',
        ];
    }
}
