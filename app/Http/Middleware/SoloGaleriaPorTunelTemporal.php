<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Por un túnel temporal de Cloudflare (*.trycloudflare.com) solo se ve la
 * Galería 3D.
 *
 * Ese túnel lo abre el botón «Encender el QR para el celular» de la galería
 * (App\Support\TunelCelular), para probar el QR y la realidad aumentada en
 * un teléfono: necesita una dirección pública con https, y localhost no lo es.
 * Pero mientras está abierto, cualquiera con el enlace llega a este
 * computador, donde el panel entra sin segundo factor
 * (SEGUNDO_FACTOR_EN_LOCAL). Así que por esa puerta solo pasa la galería; lo
 * demás lleva a ella.
 *
 * Los archivos estáticos (los modelos, las fotos, el javascript) no pasan por
 * aquí: los sirve el servidor web directamente. El sitio de producción usa su
 * propio dominio y no se ve afectado.
 */
class SoloGaleriaPorTunelTemporal
{
    /** Lo que sí se abre por el túnel temporal. */
    private const PERMITIDO = ['galeria-3d', 'galeria-3d/*', 'a/v', 'up'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! str_ends_with(strtolower($request->getHost()), '.trycloudflare.com') || $request->is(...self::PERMITIDO)) {
            return $next($request);
        }

        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            return redirect()->route('galeria.index');
        }

        abort(403, 'Por este enlace temporal solo se puede ver la Galería 3D.');
    }
}
