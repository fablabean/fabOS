<?php

namespace App\Http\Middleware;

use App\Services\Auth\Turnstile;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * El captcha, delante de una ruta (§5).
 *
 * Va en el middleware y no dentro de cada controlador por una razón práctica:
 * así la lista de puertas protegidas **se lee entera en `routes/web.php`**. Con
 * la comprobación repartida por ocho controladores, la pregunta «¿qué formulario
 * público quedó sin captcha?» no tiene dónde contestarse, y esa es exactamente
 * la que hay que poder contestar cuando se añade una puerta nueva.
 *
 * El fallo se lanza como error de **validación** y no como un 403: el envío
 * vuelve al formulario con el mensaje al lado, que es lo que espera quien
 * simplemente tardó demasiado y se le caducó el token. Un 403 le daría una
 * página de error por algo que se arregla volviendo a pulsar.
 *
 * El campo al que se ata el mensaje se puede elegir, porque cada formulario
 * tiene el suyo y un error huérfano no se pinta en ninguna parte.
 */
class ComprobarElCaptcha
{
    public function __construct(private Turnstile $turnstile) {}

    public function handle(Request $request, Closure $next, string $campo = 'email'): Response
    {
        if ($this->turnstile->verificar(
            $request->input('cf-turnstile-response'),
            $request->ip(),
        )) {
            return $next($request);
        }

        throw ValidationException::withMessages([
            $campo => 'No pudimos comprobar que no eres un robot. Vuelve a intentarlo.',
        ]);
    }
}
