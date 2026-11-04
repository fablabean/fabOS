<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Services\Booking\BookingException;
use App\Services\Booking\OtraOpcion;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Donde quien pidió una reserva toma la opción que se le propuso (§10).
 *
 * Se entra por el enlace firmado del correo, sin tener que iniciar sesión.
 * Abrirlo no reserva nada —los correos abren enlaces solos para mostrar una
 * vista previa—: reserva el botón.
 */
class OtraOpcionController extends Controller
{
    public function show(Request $request, Reservation $reservation, int $opcion)
    {
        $o = $reservation->propuesta['opciones'][$opcion] ?? null;
        abort_unless($o, 404);

        return view('reservas.otra-opcion', [
            'reserva'  => $reservation,
            'opcion'   => $opcion,
            'cuando'   => OtraOpcion::comoSeDice(Carbon::parse($o['desde']), Carbon::parse($o['hasta'])),
            'pedida'   => OtraOpcion::comoSeDice($reservation->starts_at, $reservation->ends_at),
            'tomada'   => $reservation->propuesta['tomada'] ?? null,
            'abierta'  => $reservation->status === 'solicitada',
            'accion'   => $request->fullUrl(),
        ]);
    }

    public function store(Request $request, Reservation $reservation, int $opcion)
    {
        try {
            $r = app(OtraOpcion::class)->tomar($reservation, $opcion);
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Listo: quedó reservada ' . OtraOpcion::comoSeDice($r->starts_at, $r->ends_at)
            . ($r->supervisor ? '. Te atiende ' . $r->supervisor->name : '') . '. Te llega la confirmación por correo.');
    }
}
