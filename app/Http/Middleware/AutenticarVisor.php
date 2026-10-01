<?php

namespace App\Http\Middleware;

use App\Models\Recorrido\Equipo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Las gafas, por su token: `Authorization: Bearer <token>`.
 *
 * Se guarda solo el hash. Emparejar otra vez el mismo equipo cambia el token,
 * y el visor anterior queda fuera: así se reemplaza unas gafas descargadas.
 */
class AutenticarVisor
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $equipo = $token ? Equipo::with('partida')->where('visor_token_hash', hash('sha256', $token))->first() : null;

        if (! $equipo) {
            return response()->json([
                'error' => 'visor_no_emparejado',
                'mensaje' => 'Este visor no está emparejado con ningún equipo. Empareja con el código del equipo.',
            ], 401);
        }

        $equipo->forceFill(['visor_visto_at' => now()])->saveQuietly();
        $request->attributes->set('equipo', $equipo);

        return $next($request);
    }
}
