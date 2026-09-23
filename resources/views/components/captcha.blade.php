{{--
    El widget de Turnstile (§5).

    Se pone dentro de todo formulario público que mande correo. Si no hay
    claves configuradas no pinta nada y no carga ningún script: una instalación
    recién montada, o la de alguien que no usa Cloudflare, sigue funcionando
    igual.

    Casi nunca le pide nada a la persona —resuelve solo mirando el navegador—,
    así que lo normal es ver una casilla que se marca sola y ya. Por eso va
    justo encima del botón y sin explicación: explicar algo que no pide nada
    preocupa más que tranquiliza.
--}}
{{--
    `discreto`: el widget no se dibuja salvo que haga falta resolver algo.

    Turnstile casi siempre pasa solo mirando el navegador, y en ese caso la
    casilla de 300×65 que queda es un cartel de Cloudflare más grande que el
    campo que protege. Con `interaction-only` no ocupa nada mientras no pida
    nada, y aparece entero el día que sí. Sigue validando igual: lo que cambia
    es si se ve, no si se comprueba.

    No es el defecto: en un formulario que manda correo, ver la casilla marcada
    antes de pulsar «enviar» tranquiliza. Se usa donde el widget pesa más que
    el trámite —una caja de una línea— y no donde hay algo en juego.
--}}
@props(['accion' => null, 'discreto' => false])

@php($turnstile = app(\App\Services\Auth\Turnstile::class))

@if ($turnstile->estaActivo())
    <div class="cf-turnstile"
         data-sitekey="{{ $turnstile->claveDelSitio() }}"
         {{-- A que puerta pertenece este token. Cloudflare lo graba dentro al
              emitirlo, asi que un token del formulario de ingreso no sirve
              para mandar mil postulaciones a practicas. Es el nombre de la
              ruta a la que envia este formulario. --}}
         @if ($accion) data-action="{{ \App\Services\Auth\Turnstile::accionDe($accion) }}" @endif
         data-language="es"
         {{-- El tema sigue al del sistema, como el resto del sitio. --}}
         data-theme="auto"
         @if ($discreto)
             data-appearance="interaction-only"
             {{-- Se estira a lo que haya: el día que aparezca, encaja en el
                  formulario en vez de desbordarlo en un teléfono. --}}
             data-size="flexible"
         @endif
         style="margin:{{ $discreto ? '0' : '.9rem 0' }}"></div>

    {{-- Una pagina puede llevar DOS widgets —la de escribir el codigo tiene
         el formulario de entrar y el de reenviar—, y el script se carga una
         sola vez: Turnstile dibuja solo todos los `.cf-turnstile` que
         encuentre. --}}
    @once
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>

        {{-- El token caduca a los cinco minutos. Quien deja el formulario
             abierto y vuelve despues se encontraria con que «no se pudo
             comprobar» sin haber hecho nada raro: esto lo renueva antes. --}}
        <script>
            (function () {
                setInterval(function () {
                    if (window.turnstile) {
                        window.turnstile.reset();
                    }
                }, 4 * 60 * 1000);
            })();
        </script>
    @endonce
@endif
