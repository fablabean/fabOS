<?php

namespace App\Services\Booking;

use App\Models\Reservation;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Quién llegó a una asesoría, y quién no (§10).
 *
 * Una asesoría no tiene QR que escanear: no reserva una máquina sino el
 * tiempo de una persona, y a una persona no se le pega un código en la
 * frente. Por eso la llegada la valida **quien atiende**, desde su cuenta:
 * es quien está delante y quien sabe si la otra persona vino.
 *
 * Tres cosas, y de quién es cada una:
 *
 *  · **Llegó.** La dice quien atiende. Vale desde un rato antes de la hora
 *    hasta días después: quien atendió y se olvidó de validar tiene que
 *    poder hacerlo al día siguiente, en vez de que la asesoría figure como
 *    «no se presentó» para alguien que sí vino.
 *  · **No vino.** También de quien atiende, pasada la tolerancia. Es la
 *    única forma en que una asesoría se marca como no presentada: el barrido
 *    automático de ausencias no las toca, porque nadie escanea nada.
 *  · **No me atendieron.** La dice quien pidió, mientras quien atiende no
 *    haya validado. Es la otra cara: la asesoría se cancela con esa razón
 *    escrita, que es lo que la coordinación necesita leer.
 *
 * Cada una es un acto de una persona concreta, y ninguna se puede hacer en
 * nombre de otra: quien pidió no puede decir que vino, y quien atiende no
 * puede decir que no lo atendieron.
 */
class AsistenciaDeAsesoria
{
    /** Cuántos días después de la hora todavía se puede validar una llegada. */
    public const DIAS_PARA_VALIDAR = 3;

    /**
     * @throws BookingException
     */
    public function llego(Reservation $asesoria, User $quienAtiende, ?CarbonInterface $cuando = null): Reservation
    {
        $this->exigirQueSeaAsesoria($asesoria);
        $this->exigirQueAtienda($asesoria, $quienAtiende);

        if ($asesoria->checked_in_at) {
            throw new BookingException('Esta asesoría ya está validada.');
        }

        if (! in_array($asesoria->status, ['confirmada', 'en_curso'], true)) {
            throw new BookingException(
                'Esta asesoría está ' . mb_strtolower(Reservation::ESTADOS[$asesoria->status] ?? $asesoria->status)
                . ' y no admite llegada.'
            );
        }

        $ahora = $cuando ?? now();
        $abre = $asesoria->starts_at->copy()->subMinutes(config('fabos.checkin.antes'));

        if ($ahora->lessThan($abre)) {
            throw new BookingException(
                'Todavía es temprano: se puede validar desde las '
                . $abre->timezone(config('fabos.lab.timezone'))->format('H:i') . '.'
            );
        }

        if ($ahora->greaterThan($asesoria->ends_at->copy()->addDays(self::DIAS_PARA_VALIDAR))) {
            throw new BookingException(
                'Pasaron más de ' . self::DIAS_PARA_VALIDAR . ' días: ya no se puede validar. Si hace falta, se corrige desde el panel.'
            );
        }

        /*
         * La llegada queda a la HORA DE LA ASESORÍA, siempre.
         *
         * Validar es decir «vino a su hora», no «pulsé el botón a las 15:44».
         * Quien atiende valida cuando se acuerda —al empezar, al terminar, al
         * día siguiente— y ninguna de esas horas es la de la llegada. La hora
         * de inicio sí: es la que se acordó, y a la que se le espera.
         */
        $asesoria->update([
            'checked_in_at' => $asesoria->starts_at,
            'status'        => $ahora->greaterThan($asesoria->ends_at) ? 'completada' : 'en_curso',
            'status_reason' => 'Llegada validada por ' . $quienAtiende->name,
        ]);

        return $asesoria->refresh();
    }

    /**
     * @throws BookingException
     */
    public function noVino(Reservation $asesoria, User $quienAtiende, ?CarbonInterface $cuando = null): Reservation
    {
        $this->exigirQueSeaAsesoria($asesoria);
        $this->exigirQueAtienda($asesoria, $quienAtiende);

        if ($asesoria->checked_in_at) {
            throw new BookingException('Esta asesoría ya está validada como atendida: no se puede marcar ausente.');
        }

        if ($asesoria->status !== 'confirmada') {
            throw new BookingException('Esta asesoría ya no está esperando a nadie.');
        }

        $ahora = $cuando ?? now();
        $desde = $asesoria->starts_at->copy()->addMinutes(config('fabos.checkin.tolerancia'));

        // Antes de la tolerancia no se puede: la persona todavía puede llegar.
        if ($ahora->lessThan($desde)) {
            throw new BookingException(
                'Todavía está dentro de la tolerancia. Se puede marcar desde las '
                . $desde->timezone(config('fabos.lab.timezone'))->format('H:i') . '.'
            );
        }

        $asesoria->update([
            'status'        => 'no_show',
            'status_reason' => 'No se presentó, según ' . $quienAtiende->name . ', que la atendía',
        ]);

        return $asesoria->refresh();
    }

    /**
     * @throws BookingException
     */
    public function noMeAtendieron(Reservation $asesoria, User $quienPidio, ?CarbonInterface $cuando = null): Reservation
    {
        $this->exigirQueSeaAsesoria($asesoria);

        if ($asesoria->user_id !== $quienPidio->id) {
            throw new BookingException('Esta asesoría no es tuya.');
        }

        // Si quien atiende ya validó, la versión de que no lo atendieron no
        // entra por aquí: eso se discute con la coordinación, con las dos
        // versiones delante.
        if ($asesoria->checked_in_at) {
            throw new BookingException(
                'Quien te atendía ya validó la asesoría. Si no fue así, escríbele a la coordinación.'
            );
        }

        if ($asesoria->status !== 'confirmada') {
            throw new BookingException('Esta asesoría ya no está en pie.');
        }

        $ahora = $cuando ?? now();
        $desde = $asesoria->starts_at->copy()->addMinutes(config('fabos.checkin.tolerancia'));

        if ($ahora->lessThan($desde)) {
            throw new BookingException(
                'Dale un poco más de tiempo: se puede reportar desde las '
                . $desde->timezone(config('fabos.lab.timezone'))->format('H:i') . '.'
            );
        }

        $asesoria->update([
            'status'        => 'cancelada',
            'status_reason' => 'No lo atendieron, según ' . $quienPidio->name . ', que la pidió',
        ]);

        return $asesoria->refresh();
    }

    private function exigirQueSeaAsesoria(Reservation $reserva): void
    {
        if (! $reserva->esAsesoria()) {
            throw new BookingException('Esto solo vale para asesorías: las reservas de equipo se validan con el QR.');
        }
    }

    /** Quien atiende, o alguien del backoffice: la coordinación corrige por quien se olvidó. */
    private function exigirQueAtienda(Reservation $asesoria, User $quien): void
    {
        $atiende = $asesoria->reservable_type === User::class && (int) $asesoria->reservable_id === $quien->id;

        if (! $atiende && ! $quien->hasAnyRole(User::ROLES_BACKOFFICE)) {
            throw new BookingException('Esta asesoría la valida quien la atiende.');
        }
    }
}
