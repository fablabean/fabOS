<?php

namespace App\Http\Middleware;

use App\Services\Analitica\Analitica;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Anota a los rastreadores que piden páginas del sitio (§20).
 *
 * Google, Bing y los de IA no ejecutan el script de analítica: la única forma
 * de saber si nos están leyendo es mirar quién pide qué. Se anota al terminar,
 * cuando la respuesta ya salió, para no retrasar a nadie.
 */
class AnotarRastreadores
{
    public function __construct(private Analitica $analitica) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! $request->isMethod('GET') || $request->is('admin*', 'livewire*', 'storage/*', 'a/*')) {
            return;
        }

        if ($this->analitica->rastreadorDe($request->userAgent()) === null) {
            return;
        }

        $this->analitica->rastreo($request, $response->getStatusCode());
    }
}
