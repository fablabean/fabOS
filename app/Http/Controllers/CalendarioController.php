<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Models\User;
use App\Services\Calendar\Calendario;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Llevarse lo reservado al calendario propio (§8).
 *
 * Dos puertas, y hacen cosas distintas:
 *
 *  · **Descargar una reserva** es una foto: se copia al calendario y ahí se
 *    queda. Si luego se cancela, la copia no se entera.
 *  · **Suscribirse** es un cable: Outlook vuelve a pedir la lista cada pocas
 *    horas, así que lo que cambia aquí aparece allá solo.
 */
class CalendarioController extends Controller
{
    public function __construct(private Calendario $calendario) {}

    /** El archivo de una reserva, para «añadir a mi calendario». */
    public function reserva(Request $request, Reservation $reservation)
    {
        $quien = $request->user();

        // La suya, o la que atiende. Una reserva dice quién, cuándo y para
        // qué: no es de nadie más.
        abort_unless(
            $reservation->user_id === $quien->id
                || ($reservation->reservable_type === User::class
                    && $reservation->reservable_id === $quien->id),
            403,
        );

        $reservation->load(['reservable', 'user', 'advisoryAsset', 'advisoryArea']);

        return response($this->calendario->deUnaReserva($reservation))
            ->header('Content-Type', 'text/calendar; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="reserva-' . $reservation->id . '.ics"');
    }

    /**
     * El calendario suscribible de una persona.
     *
     * **Sin sesión, a propósito.** Quien pide esta dirección es Outlook, no una
     * persona: no hay dónde escribir una contraseña. Por eso la dirección es el
     * secreto, y por eso se puede revocar generando otra.
     */
    public function suscripcion(string $token)
    {
        $persona = User::where('calendar_token', $token)->firstOrFail();

        $reservas = Reservation::query()
            ->where(function ($q) use ($persona) {
                $q->where('user_id', $persona->id)
                    ->orWhere(function ($q) use ($persona) {
                        $q->where('reservable_type', User::class)
                            ->where('reservable_id', $persona->id);
                    });
            })
            // Ni el año pasado ni el que viene: un calendario con dos mil
            // eventos tarda en sincronizar y nadie mira tan atrás.
            ->where('ends_at', '>=', now()->subMonths(2))
            ->with(['reservable', 'user', 'advisoryAsset', 'advisoryArea'])
            ->orderBy('starts_at')
            ->get()
            // El reservable de una reserva de equipo es polimorfico y puede
            // venir vacio si el equipo se borro: se deja fuera antes de
            // escribir el evento, para no publicar «Reserva» a secas.
            ->each(fn (Reservation $r) => $r->relationLoaded('reservable'));

        return response($this->calendario->deUnaPersona($persona, $reservas))
            ->header('Content-Type', 'text/calendar; charset=utf-8');
    }

    /**
     * Crea —o rehace— la dirección secreta.
     *
     * Rehacerla es la forma de revocar la anterior: si alguien compartió la
     * suya sin querer, con un clic la vieja deja de servir.
     */
    public function suscribirme(Request $request)
    {
        $quien = $request->user();

        $quien->forceFill(['calendar_token' => Str::random(48)])->save();

        return back()->with('status', 'Tu calendario tiene una dirección nueva. La anterior dejó de funcionar.');
    }
}
