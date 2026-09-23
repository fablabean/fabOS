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

    @if ($discreto)
        {{-- Una señal de que esto está protegido.

             Sin el widget a la vista no queda nada que lo diga, y un formulario
             público sin ninguna marca invita a probar suerte. Un escudo y tres
             palabras bastan: quien va a escribir de verdad ni lo mira, y quien
             iba a automatizarlo ve que hay algo.

             Va el enlace a la privacidad de Cloudflare porque el widget entero
             lo lleva, y esconderlo no debería esconder también de quién es el
             servicio que mira el navegador de quien entra. --}}
        <p style="display:flex;align-items:center;gap:.3rem;margin:.55rem 0 0;
                  font-size:.72rem;line-height:1.3;color:var(--muted,#6b7280)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"
                 style="width:.85em;height:.85em;flex:none">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                <path d="m9 12 2 2 4-4"/>
            </svg>
            Protegido por
            <a href="https://www.cloudflare.com/privacypolicy/" target="_blank" rel="noopener nofollow"
               style="color:inherit;text-decoration:underline">Cloudflare</a>
        </p>
    @endif

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
