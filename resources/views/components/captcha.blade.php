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
@php($turnstile = app(\App\Services\Auth\Turnstile::class))

@if ($turnstile->estaActivo())
    <div class="cf-turnstile"
         data-sitekey="{{ $turnstile->claveDelSitio() }}"
         data-language="es"
         {{-- El tema sigue al del sistema, como el resto del sitio. --}}
         data-theme="auto"
         style="margin:.9rem 0"></div>

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
