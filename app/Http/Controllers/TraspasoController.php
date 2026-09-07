<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Models\ReservationTransfer;
use App\Models\User;
use App\Services\Booking\BookingException;
use App\Services\Booking\TraspasoDeAtencion;
use Illuminate\Http\Request;

/**
 * Pasarle a otra persona lo que a uno le toca atender (§10).
 *
 * Todo ocurre desde Mi cuenta, y todo vuelve a Mi cuenta: es donde cada quien
 * ve lo que atiende y lo que le proponen.
 */
class TraspasoController extends Controller
{
    public function __construct(private TraspasoDeAtencion $traspasos) {}

    public function proponer(Request $request, Reservation $reservation)
    {
        $datos = $request->validate([
            'a'    => ['required', 'integer', 'exists:users,id'],
            'nota' => ['nullable', 'string', 'max:500'],
        ]);

        $a = User::findOrFail($datos['a']);

        return $this->decidir(
            fn () => $this->traspasos->proponer($reservation, $request->user(), $a, $datos['nota'] ?? null),
            'Propuesto a ' . $a->name . '. Sigue a tu nombre hasta que acepte.',
        );
    }

    public function aceptar(Request $request, ReservationTransfer $transfer)
    {
        return $this->decidir(
            fn () => $this->traspasos->aceptar($transfer, $request->user()),
            'Ahora la atiendes tú. Ya está en tu agenda.',
        );
    }

    public function rechazar(Request $request, ReservationTransfer $transfer)
    {
        $datos = $request->validate(['motivo' => ['nullable', 'string', 'max:500']]);

        return $this->decidir(
            fn () => $this->traspasos->rechazar($transfer, $request->user(), $datos['motivo'] ?? null),
            'Rechazada. Sigue a nombre de quien te la propuso.',
        );
    }

    public function retirar(Request $request, ReservationTransfer $transfer)
    {
        return $this->decidir(
            fn () => $this->traspasos->retirar($transfer, $request->user()),
            'Propuesta retirada. Sigue a tu nombre.',
        );
    }

    private function decidir(callable $accion, string $bien)
    {
        try {
            $accion();
        } catch (BookingException $e) {
            return redirect()->route('home')->withErrors(['traspaso' => $e->getMessage()]);
        }

        return redirect()->route('home')->with('status', $bien);
    }
}
