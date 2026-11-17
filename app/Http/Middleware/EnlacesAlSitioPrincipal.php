<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * En el subdominio de una zona, los enlaces del sitio van al dominio principal.
 *
 * Laravel arma las direcciones con el host de la petición: desde
 * robotica.fablabean.com, «Ingresar» o el logo apuntarían a
 * robotica.fablabean.com/ingresar, que aquí es el nombre de un módulo y da 404.
 * Las hojas de estilo y las fotos, igual.
 */
class EnlacesAlSitioPrincipal
{
    public function handle(Request $request, Closure $next): Response
    {
        URL::forceRootUrl(rtrim((string) config('app.url'), '/'));

        return $next($request);
    }
}
