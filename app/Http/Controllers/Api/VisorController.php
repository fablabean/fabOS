<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Recorrido\Equipo;
use App\Services\Recorrido\Juego;
use App\Services\Recorrido\JuegoException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Lo que necesitan las gafas del líder (docs/RECORRIDOS-API.md).
 *
 * Las respuestas llevan siempre el estado completo del equipo: así el visor
 * nunca tiene que adivinar qué pintar después de una acción.
 */
class VisorController extends Controller
{
    public function __construct(private Juego $juego) {}

    public function emparejar(Request $request): JsonResponse
    {
        $datos = $request->validate(['codigo' => ['required', 'string', 'max:12']]);

        $equipo = Equipo::with('partida')
            ->where('codigo', strtoupper(trim($datos['codigo'])))
            ->whereHas('partida', fn ($q) => $q->where('estado', '!=', 'terminada'))
            ->first();

        if (! $equipo) {
            return response()->json([
                'error' => 'codigo_invalido',
                'mensaje' => 'No hay un equipo en juego con ese código.',
            ], 404);
        }

        $token = Str::random(48);
        $equipo->forceFill(['visor_token_hash' => hash('sha256', $token), 'visor_visto_at' => now()])->save();

        return response()->json([
            'token' => $token,
            // Todas las imágenes de una vez: las gafas las bajan ahora, con
            // buena señal y sin prisa, y no cuando el líder espera la pista.
            'pistas' => $this->juego->pistas($equipo->partida),
            'estado' => $this->juego->estado($equipo),
        ]);
    }

    /** La misma lista del emparejamiento, para unas gafas que se reinician con su token. */
    public function pistas(Request $request): JsonResponse
    {
        return response()->json(['pistas' => $this->juego->pistas($this->equipo($request)->partida)]);
    }

    public function estado(Request $request): JsonResponse
    {
        return response()->json(['estado' => $this->juego->estado($this->equipo($request))]);
    }

    public function secuencia(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'botones'   => ['required', 'array', 'size:4'],
            'botones.*' => ['required', 'integer', 'between:1,' . count(Equipo::BOTONES)],
        ]);

        $equipo = $this->equipo($request);

        try {
            $correcta = $this->juego->marcarSecuencia($equipo, $datos['botones']);
        } catch (JuegoException $e) {
            return $this->rechazo($equipo, $e);
        }

        return response()->json([
            'correcta' => $correcta,
            'mensaje' => $correcta
                ? ($equipo->terminado() ? '¡Terminaron el recorrido!' : '¡Correcto! Nueva pista.')
                : 'Esa no es la secuencia. Suma penalización: revisen y vuelvan a marcar.',
            'estado' => $this->juego->estado($equipo->fresh()),
        ]);
    }

    public function lider(Request $request): JsonResponse
    {
        $datos = $request->validate(['integrante_id' => ['nullable', 'integer']]);
        $equipo = $this->equipo($request);

        try {
            $this->juego->elegirLider($equipo, $datos['integrante_id'] ?? null);
        } catch (JuegoException $e) {
            return $this->rechazo($equipo, $e);
        }

        return response()->json(['estado' => $this->juego->estado($equipo->fresh())]);
    }

    private function equipo(Request $request): Equipo
    {
        return $request->attributes->get('equipo');
    }

    private function rechazo(Equipo $equipo, JuegoException $e): JsonResponse
    {
        return response()->json([
            'error' => 'no_permitido',
            'mensaje' => $e->getMessage(),
            'estado' => $this->juego->estado($equipo->fresh()),
        ], 409);
    }
}
