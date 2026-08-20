<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cierra el backoffice a quien administra y no ha pasado el segundo factor (§16).
 *
 * Se aplica al panel entero y no a cada pantalla: si dependiera de recordarlo
 * en cada sitio, tarde o temprano habría una puerta sin cerrar.
 */
class RequiereSegundoFactor
{
    /** Rutas que deben seguir accesibles: son las que llevan a resolverlo. */
    private const LIBRES = ['segundo-factor', 'segundo-factor/*', 'salir'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->segundoFactorObligatorio()) {
            return $next($request);
        }

        if ($request->is(self::LIBRES)) {
            return $next($request);
        }

        // Sin configurar: se manda a configurarlo, no se le niega el paso sin más.
        if (! $user->tieneSegundoFactor()) {
            return redirect()->route('dosfactores.configurar');
        }

        // Configurado pero no verificado en esta sesión.
        if (! $request->session()->get('segundo_factor_verificado')) {
            return redirect()->route('dosfactores.verificar');
        }

        return $next($request);
    }
}
