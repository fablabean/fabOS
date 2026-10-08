<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Iot\Dispositivo;
use App\Services\Iot\Turnos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lo que pregunta el aparato: ¿encendido o apagado? (docs/IOT-API.md)
 *
 * Una sola pregunta, a propósito. El aparato no decide nada ni guarda nada:
 * obedece la respuesta, y si deja de recibirla se apaga solo.
 */
class DispositivoController extends Controller
{
    public function estado(Request $request, Turnos $turnos): JsonResponse
    {
        $dispositivo = Dispositivo::porClave($request->bearerToken());

        if (! $dispositivo) {
            return response()->json([
                'error' => 'clave_invalida',
                'mensaje' => 'La clave no corresponde a ningún dispositivo. Genera una nueva en el panel.',
            ], 401);
        }

        // Cada pregunta es la señal de vida: así el portal sabe que encender
        // de verdad enciende algo.
        $dispositivo->forceFill(['visto_at' => now()])->saveQuietly();

        return response()->json($turnos->estado($dispositivo));
    }
}
