{{-- La guía de reservas (§10): una pregunta, una respuesta, uno de los cuatro
     caminos. Se incluye en reservas y en la portada; la condición de que la IA
     esté encendida la pone quien la incluye. --}}
    {{-- La guía (§10): quien no sabe cuál de los cuatro escribe lo que
         necesita y se le dice cuál, y por qué. Una pregunta, una
         respuesta, sin hilo. Solo si la IA está encendida. --}}
        @php $guia = session('guia'); @endphp
<style>
    /* La guía: una caja, una pregunta, una respuesta. */
    .guia{padding:0 0 2rem}
    .guia form{background:var(--surface);border:1px solid var(--rule);border-radius:8px;padding:1.2rem 1.4rem}
    .guia label{display:block;margin-bottom:.6rem}
    .guia label strong{display:block;font-size:1.05rem}
    .guia .fila{display:flex;gap:.6rem;flex-wrap:wrap}
    .guia .fila input{flex:1 1 20rem;padding:.7rem .8rem;font:inherit;border:1px solid var(--rule);
                      border-radius:4px;background:var(--ground);color:var(--ink)}
    .guia .fila input:focus{outline:2px solid var(--accent);outline-offset:1px}
    .guia .error{color:#9B2C2C;font-size:.9rem;margin:.5rem 0 0}
    .guia .respuesta{margin-top:.8rem;padding:1.1rem 1.4rem;border-radius:8px;
                     background:color-mix(in srgb,var(--accent) 12%,transparent);border-left:4px solid var(--accent)}
    .guia .respuesta.nada{background:color-mix(in srgb,var(--ink) 6%,transparent);border-left-color:var(--rule)}
    .guia .cual{margin:0 0 .4rem;font-size:1.25rem;font-weight:700}
    .guia .cual a{text-decoration:none}
    .guia .porque{margin:0}
    /* La advertencia: destacada sin ser una alarma. Lo que dice no es un
       error de quien pregunta, es algo que más vale saber antes de venir. */
    .guia .ojo{
        margin:.7rem 0 0;padding:.6rem .8rem;border-radius:6px;font-size:.92rem;
        background:color-mix(in srgb,var(--ink) 7%,transparent);
        border-left:3px solid color-mix(in srgb,var(--accent) 55%,transparent);
    }
    .guia .pie{margin:.6rem 0 0;font-size:.8rem;color:var(--muted)}

    /* Al volver con la respuesta se salta aquí. La barra de arriba es fija,
       así que sin este margen el título de la caja queda debajo de ella. */
    .guia{scroll-margin-top:5rem}

    /* El botón mientras piensa.
       Una consulta a la IA tarda unos segundos, y un botón que no cambia
       parece un botón que no funcionó: se vuelve a pulsar, y la segunda
       pulsación cuesta otra llamada. */
    .guia .decirme{display:inline-flex;align-items:center;gap:.5rem}
    .guia .decirme .aro{
        display:none;width:.85rem;height:.85rem;flex:none;border-radius:50%;
        border:2px solid color-mix(in srgb,var(--surface) 45%,transparent);
        border-top-color:var(--surface);animation:guia-gira .7s linear infinite;
    }
    .guia .decirme[aria-busy="true"]{opacity:.85;cursor:progress}
    .guia .decirme[aria-busy="true"] .aro{display:block}
    @keyframes guia-gira{to{transform:rotate(360deg)}}
    /* Quien pidió menos movimiento no ve girar nada, pero sigue viendo que
       el botón cambió: el texto y el punto se quedan quietos. */
    @media (prefers-reduced-motion:reduce){
        .guia .decirme .aro{animation:none}
    }
</style>
        <section class="guia" id="guia">
            <form method="POST" action="{{ route('publico.reservas.guia') }}">
                @csrf
                <label for="necesidad">
                    <span class="rotulo" style="margin-bottom:.3rem">¿No sabes cuál?</span>
                    <strong>Escribe qué necesitas y te guiaremos en la opción que debes tomar.</strong>
                </label>
                <div class="fila">
                    <input id="necesidad" name="necesidad" type="text" required minlength="8" maxlength="600"
                           placeholder="Quiero hacer un trofeo en acrílico pero nunca he usado la láser"
                           value="{{ old('necesidad') }}" autocomplete="off">
                    {{-- El texto va envuelto: al pasar a «Pensando…» se cambia
                         solo eso y el aro sigue donde está, sin reconstruir el
                         botón ni hacerlo saltar de ancho. --}}
                    <button type="submit" class="btn decirme"><span class="aro" aria-hidden="true"></span><span class="que-dice">Decirme</span></button>
                </div>
                @error('necesidad') <p class="error">{{ $message }}</p> @enderror
                {{-- Discreto: aquí la casilla de Cloudflare era más grande que
                     la caja de una línea que protege. Sigue comprobando igual;
                     aparece solo si hay algo que resolver. --}}
                <x-captcha accion="publico.reservas.guia" :discreto="true"/>
            </form>

            <script>
                /*
                 * El botón, mientras piensa.
                 *
                 * La consulta tarda unos segundos y un botón que no cambia
                 * parece un botón que no funcionó: se vuelve a pulsar, y la
                 * segunda pulsación cuesta otra llamada a la API.
                 *
                 * `aria-busy` y no `disabled`: un botón deshabilitado se cae
                 * del orden de tabulación y quien navega con teclado o lector
                 * de pantalla pierde el sitio justo cuando está esperando. Lo
                 * que impide el segundo envío es la bandera de aquí abajo.
                 */
                (function () {
                    var form = document.currentScript.previousElementSibling;
                    var boton = form.querySelector('.decirme');

                    if (!boton) return;

                    var enviando = false;

                    form.addEventListener('submit', function (e) {
                        if (enviando) { e.preventDefault(); return; }
                        if (!form.checkValidity()) return;

                        enviando = true;
                        boton.setAttribute('aria-busy', 'true');
                        boton.querySelector('.que-dice').textContent = 'Pensando…';
                    });

                    /*
                     * Y al volver con el botón del navegador, como estaba.
                     * La página se restaura de la memoria tal cual se dejó
                     * —«Pensando…» incluido— y quedaría un botón que no
                     * responde y dice que está trabajando.
                     */
                    window.addEventListener('pageshow', function () {
                        enviando = false;
                        boton.removeAttribute('aria-busy');
                        boton.querySelector('.que-dice').textContent = 'Decirme';
                    });
                })();
            </script>

            @if ($guia)
                <div class="respuesta {{ $guia['camino'] === 'ninguno' ? 'nada' : '' }}">
                    @if ($guia['camino'] !== 'ninguno')
                        {{-- «Sugerido» y no «te toca»: esto orienta, no manda.
                             Quien llega puede elegir otro camino igual. --}}
                        <p class="rotulo" style="margin-bottom:.2rem">Sugerido</p>
                        <p class="cual"><a href="{{ $guia['url'] }}">{{ $guia['titulo'] }} →</a></p>
                    @endif
                    <p class="porque">{{ $guia['porque'] }}</p>
                    {{-- Lo que siempre se dice de este camino. No lo escribe la
                         IA: lo pone el laboratorio en Comunicaciones → Guía de
                         reservas, y por eso sale siempre y no cuando el modelo
                         se acuerda. Es donde van los malentendidos que cuestan
                         un viaje —«reservé la sala de la láser» no es «puedo
                         usar la láser»—. --}}
                    @if (filled($guia['advertencia'] ?? null))
                        <p class="ojo">{{ $guia['advertencia'] }}</p>
                    @endif
                    <p class="pie">Es una orientación; las tarjetas de abajo dicen qué hace cada camino. No es un chat: aquí solo se responde por dónde ir.</p>
                </div>
            @endif
        </section>
