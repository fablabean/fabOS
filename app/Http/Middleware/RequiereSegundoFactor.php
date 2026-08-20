<?php

namespace App\Http\Middleware;

use App\Support\FactoresDeSesion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * El backoffice exige **dos factores distintos** (§16).
 *
 * Se aplica al panel entero y no a cada pantalla: si dependiera de recordarlo
 * en cada sitio, tarde o temprano habría una puerta sin cerrar.
 *
 * No exige una combinación concreta —correo, app o carné valen igual— sino que
 * sean dos. Lo que protege no es cuál se usó: es que un solo teléfono robado, o
 * un solo buzón comprometido, no alcance para encender los cobros ni para
 * mirar el libro contable.
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

        if (FactoresDeSesion::cuantos($request) >= 2) {
            return $next($request);
        }

        // Sin configurar: se manda a configurarlo, no se le niega el paso sin más.
        if (! $user->tieneSegundoFactor()) {
            return redirect()->route('dosfactores.configurar');
        }

        // Ya entró con la app y le falta el otro factor. Pedirle el mismo código
        // otra vez no probaría nada nuevo, así que se le manda al correo.
        if (FactoresDeSesion::tiene($request, FactoresDeSesion::APP)) {
            return redirect()->route('dosfactores.otroFactor');
        }

        return redirect()->route('dosfactores.verificar');
    }
}
