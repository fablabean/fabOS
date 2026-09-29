<?php

namespace App\Http\Middleware;

use App\Support\BloqueoDeReservas;
use Closure;
use Illuminate\Http\Request;

/**
 * Con el laboratorio bloqueado, las pantallas de elegir hora no se abren.
 *
 * El servicio ya rechaza la reserva, pero enseñar el calendario lleno de
 * horas libres invita a intentarlo, y el «no» llega después de elegir. Aquí
 * se dice antes: en lugar del calendario, el motivo y cuándo se reabre.
 */
class ReservasAbiertas
{
    public function handle(Request $request, Closure $next)
    {
        if (! BloqueoDeReservas::activo()) {
            return $next($request);
        }

        return response()->view('reservas.bloqueadas');
    }
}
