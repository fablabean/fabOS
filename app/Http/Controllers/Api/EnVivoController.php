<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EnVivo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lo que pregunta una presentación en vivo mientras está abierta.
 *
 * La pide un módulo aislado (origen «null», sin cookies), así que todo sale
 * con CORS abierto y nada depende de la sesión. Los envíos van como
 * formulario, no como JSON, para que el navegador no tenga que preguntar
 * antes (preflight).
 */
class EnVivoController extends Controller
{
    private const QUIEN = '/^[A-Za-z0-9]{8,40}$/';

    public function __construct(private EnVivo $envivo) {}

    public function estado(Request $request, string $sala): JsonResponse
    {
        abort_unless($this->envivo->existe($sala), 404);

        return $this->json($this->envivo->estado($sala, $this->quien($request), $request->query('profe')));
    }

    public function votar(Request $request, string $sala): JsonResponse
    {
        abort_unless($this->envivo->existe($sala), 404);
        $quien = $this->quien($request);
        abort_unless($quien && is_numeric($request->input('opcion')), 422);

        $ok = $this->envivo->votar($sala, $quien, (string) $request->input('pregunta'), (int) $request->input('opcion'));
        abort_unless($ok, 422);

        return $this->json($this->envivo->estado($sala, $quien, $request->input('profe')));
    }

    public function control(Request $request, string $sala): JsonResponse
    {
        abort_unless($this->envivo->existe($sala), 404);
        abort_unless($this->envivo->esProfe($sala, $request->input('profe')), 403);

        match ($request->input('accion')) {
            'agenda' => $this->envivo->guardarAgenda($sala, (array) json_decode((string) $request->input('agenda'), true)),
            'seccion' => $this->envivo->guardarSeccion($sala, (string) $request->input('seccion')),
            'reiniciar-votos' => $this->envivo->reiniciarVotos($sala),
            'reiniciar-agenda' => $this->envivo->reiniciarAgenda($sala),
            default => abort(422),
        };

        return $this->json($this->envivo->estado($sala, $this->quien($request), $request->input('profe')));
    }

    private function quien(Request $request): ?string
    {
        $v = (string) $request->input('v', $request->query('v', ''));

        return preg_match(self::QUIEN, $v) ? $v : null;
    }

    private function json(array $datos): JsonResponse
    {
        return response()->json($datos, 200, [
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'no-store',
        ]);
    }
}
