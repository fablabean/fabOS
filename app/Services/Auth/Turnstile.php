<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * El captcha de Cloudflare delante de todo lo que pide un correo (§5).
 *
 * El sitio tiene puertas que **mandan correo a quien las toque**: el código de
 * ingreso, la solicitud de proyecto, la postulación a prácticas, la cotización
 * de la tienda. Todas están abiertas a internet, porque tienen que estarlo —a
 * quien no tiene cuenta hay que dejarle pedir una—.
 *
 * Ya hay límite por correo y por IP, y eso frena a una persona insistiendo. No
 * frena a un bot con mil direcciones: cada una pide «su» primer código y
 * ninguna pasa del tope. El resultado no es que alguien entre —el código sigue
 * yendo al buzón de su dueño— sino que el laboratorio manda miles de correos
 * que nadie pidió, paga por ellos y **quema su reputación de envío**, que es
 * justo lo que ya cuesta mantener aquí.
 *
 * Turnstile y no reCAPTCHA: el sitio ya sale por un túnel de Cloudflare, así
 * que no se mete a un tercero nuevo a mirar a quien visita, y casi siempre
 * resuelve sin pedirle nada a la persona —no hay semáforos que señalar—.
 *
 * ## Dos decisiones que conviene no perder
 *
 * **Sin claves, no estorba.** Si no hay `TURNSTILE_SECRET_KEY`, esto deja pasar
 * todo y no pinta nada. Un despliegue no puede dejar al laboratorio sin poder
 * entrar porque falte una variable de entorno.
 *
 * **Si Cloudflare no contesta, se deja pasar** —y se anota en el registro—. Es
 * una decisión consciente y va contra el instinto: un captcha que falla cerrado
 * convierte cualquier corte de red en «nadie puede entrar al laboratorio».
 * Aquí, además, la red bloquea cosas de forma habitual. Debajo sigue estando el
 * límite por correo y por IP, que es el que protege de verdad a una puerta
 * concreta; el captcha es la capa que quita el ruido masivo. Un token inválido
 * o ausente sí se rechaza: eso no es un corte, es una respuesta.
 */
class Turnstile
{
    private const VERIFICACION = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** Poco: esto va en mitad de un formulario y alguien está esperando. */
    private const ESPERA_SEGUNDOS = 5;

    public function estaActivo(): bool
    {
        return filled(config('fabos.turnstile.site_key'))
            && filled(config('fabos.turnstile.secret_key'));
    }

    public function claveDelSitio(): ?string
    {
        return config('fabos.turnstile.site_key');
    }

    /**
     * Si este envío viene de alguien y no de un programa.
     *
     * @param  string|null  $token  Lo que puso el widget en el formulario.
     * @param  string|null  $ip  De dónde llegó. Cloudflare la usa como pista.
     */
    public function verificar(?string $token, ?string $ip = null): bool
    {
        if (! $this->estaActivo()) {
            return true;
        }

        // Sin token no hay nada que preguntar: o el widget no se resolvió, o
        // el envío no pasó por el formulario.
        if (blank($token)) {
            return false;
        }

        try {
            $respuesta = Http::asForm()
                ->timeout(self::ESPERA_SEGUNDOS)
                ->post(self::VERIFICACION, array_filter([
                    'secret' => config('fabos.turnstile.secret_key'),
                    'response' => $token,
                    'remoteip' => $ip,
                ]));
        } catch (\Throwable $e) {
            /*
             * No se pudo preguntar. Se deja pasar, pero queda escrito: si esto
             * aparece repetido en el registro, el captcha lleva tiempo sin
             * proteger nada y nadie se ha enterado.
             */
            Log::warning('No se pudo comprobar el captcha; se deja pasar', [
                'error' => $e->getMessage(),
            ]);

            return true;
        }

        if ($respuesta->failed()) {
            Log::warning('El captcha respondió con error; se deja pasar', [
                'estado' => $respuesta->status(),
            ]);

            return true;
        }

        $ok = (bool) $respuesta->json('success', false);

        if (! $ok) {
            // Los codigos de Cloudflare distinguen «token gastado» de «clave
            // mal puesta», y sin ellos las dos se ven igual desde fuera: como
            // un formulario que no deja enviar.
            Log::info('Captcha rechazado', [
                'motivos' => $respuesta->json('error-codes', []),
            ]);
        }

        return $ok;
    }
}
