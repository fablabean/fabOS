<?php

namespace App\Http\Controllers;

use App\Services\Analitica\Analitica;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Recibe el aviso de cada página vista (§20).
 *
 * Llega con navigator.sendBeacon, como texto plano: así el navegador lo manda
 * aunque la persona ya se esté yendo de la página, y sin la pregunta previa
 * (preflight) que haría un JSON. No lleva token CSRF porque no cambia nada de
 * nadie: lo peor que puede hacer alguien que lo falsifique es contar una
 * visita de más, y para eso ya está el límite de peticiones.
 */
class AnaliticaController extends Controller
{
    public function __construct(private Analitica $analitica) {}

    public function registrar(Request $request): Response
    {
        $datos = json_decode((string) $request->getContent(), true);

        if (! is_array($datos) || ! is_string($datos['p'] ?? null) || strlen($datos['p']) > 600) {
            return response()->noContent();
        }

        $tipo = (string) ($datos['t'] ?? 'v');

        if ($tipo === 'e') {
            $evento = (string) ($datos['e'] ?? '');

            if (in_array($evento, Analitica::EVENTOS_DEL_CLIENTE, true)) {
                $this->analitica->evento($evento, origen: 'cliente', ruta: $datos['p'], request: $request);
            }

            return response()->noContent();
        }

        $this->analitica->visita($request, [
            'ruta'      => $datos['p'],
            'referente' => is_string($datos['r'] ?? null) ? substr($datos['r'], 0, 600) : null,
            'busqueda'  => is_string($datos['q'] ?? null) ? substr($datos['q'], 0, 600) : null,
            'ancho'     => is_numeric($datos['w'] ?? null) ? (int) $datos['w'] : null,
            'nombre'    => is_string($datos['n'] ?? null) ? $datos['n'] : null,
        ]);

        return response()->noContent();
    }
}
