<?php

namespace App\Http\Middleware;

use App\Support\FactoresDeSesion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * El backoffice exige **la aplicación de autenticación** (§16).
 *
 * Se aplica al panel entero y no a cada pantalla: si dependiera de recordarlo
 * en cada sitio, tarde o temprano habría una puerta sin cerrar.
 *
 * Se exige ese factor y no «dos cualesquiera» por una razón práctica: el otro
 * factor disponible es el correo, y un correo que no siempre llega convierte la
 * segunda comprobación en una forma de quedarse fuera del propio sistema. Un
 * candado que se traba solo no protege nada; hace que la gente busque cómo
 * saltárselo.
 *
 * La app no depende de la red, del proveedor de correo ni de que alguien
 * apruebe una cuenta: el código lo genera el teléfono.
 *
 * Lo que esto acepta a cambio: quien tenga el teléfono desbloqueado con la app
 * abierta entra al backoffice. Es una decisión consciente del laboratorio,
 * tomada sabiendo que la alternativa era peor.
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

        // Quien entró con la app ya la demostró en esta sesión: pedirle el mismo
        // código otra vez no probaría nada nuevo.
        if (FactoresDeSesion::tiene($request, FactoresDeSesion::APP)) {
            return $next($request);
        }

        // Sin configurar: se manda a configurarlo, no se le niega el paso sin más.
        if (! $user->tieneSegundoFactor()) {
            return redirect()->route('dosfactores.configurar');
        }

        return redirect()->route('dosfactores.verificar');
    }
}
