<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EnVivo;
use App\Services\TweetsEnVivo;
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

        return $this->json($this->envivo->estado($sala, $this->quien($request), $request->query('profe'), $this->versionMuro($request), $this->versionTweets($request)));
    }

    /** Un tweet para el muro: la IA del FabLab lo lee en la cola. */
    public function tuit(Request $request, string $sala, TweetsEnVivo $tweets): JsonResponse
    {
        abort_unless($this->envivo->existe($sala) && $tweets->activo($sala), 404);
        $quien = $this->quien($request);
        abort_unless($quien, 422);

        $error = $tweets->publicar($sala, $quien, (string) $request->input('texto'));
        if ($error) {
            return response()->json(['error' => $error], 422, ['Access-Control-Allow-Origin' => '*']);
        }

        return $this->json($this->envivo->estado($sala, $quien, $request->input('profe')));
    }

    private function versionTweets(Request $request): ?int
    {
        $t = $request->query('t');

        return is_numeric($t) ? (int) $t : null;
    }

    /** La tarjeta de quien escribe en el muro (una por navegador; se puede corregir). */
    public function aportar(Request $request, string $sala): JsonResponse
    {
        abort_unless($this->envivo->existe($sala) && $this->envivo->tieneMuro($sala), 404);
        $quien = $this->quien($request);
        abort_unless($quien && $this->envivo->aportar($sala, $quien, $request->only(array_keys(EnVivo::CAMPOS_MURO))), 422);

        return $this->json($this->envivo->estado($sala, $quien, $request->input('profe')));
    }

    /** Quien escribe su nombre recibe su ID (o lo recupera escribiendo el que ya tiene). */
    public function registro(Request $request, string $sala): JsonResponse
    {
        abort_unless($this->envivo->existe($sala) && $this->envivo->grupo($sala), 404);
        $quien = $this->quien($request);
        abort_unless($quien, 422);

        $p = $request->filled('codigo')
            ? $this->envivo->recuperar($sala, $quien, (string) $request->input('codigo'))
            : $this->envivo->registrar($sala, $quien, (string) $request->input('nombre'));

        if (! $p) {
            return response()->json(['error' => $request->filled('codigo') ? 'No encontré ese ID.' : 'Escribe tu nombre.'], 422, [
                'Access-Control-Allow-Origin' => '*',
            ]);
        }

        return $this->json(['codigo' => $p->codigo, 'nombre' => $p->nombre]);
    }

    private function versionMuro(Request $request): ?int
    {
        $m = $request->input('m', $request->query('m'));

        return is_numeric($m) ? (int) $m : null;
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
            'revelar' => $this->envivo->revelar($sala, (string) $request->input('id')),
            'quitar' => $this->envivo->quitarDelMuro($sala, (string) $request->input('id')),
            'destacar' => $this->envivo->destacar($sala, $request->input('id')),
            'vaciar-muro' => $this->envivo->vaciarMuro($sala),
            'reiniciar-votos' => $this->envivo->reiniciarVotos($sala),
            'reiniciar-agenda' => $this->envivo->reiniciarAgenda($sala),
            'quitar-tweet' => app(TweetsEnVivo::class)->quitar($sala, (string) $request->input('id')),
            'vaciar-tweets' => app(TweetsEnVivo::class)->vaciar($sala),
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
