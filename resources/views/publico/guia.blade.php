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
    .guia .pie{margin:.6rem 0 0;font-size:.8rem;color:var(--muted)}
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
                    <button type="submit" class="btn">Decirme</button>
                </div>
                @error('necesidad') <p class="error">{{ $message }}</p> @enderror
                {{-- Discreto: aquí la casilla de Cloudflare era más grande que
                     la caja de una línea que protege. Sigue comprobando igual;
                     aparece solo si hay algo que resolver. --}}
                <x-captcha accion="publico.reservas.guia" :discreto="true"/>
            </form>

            @if ($guia)
                <div class="respuesta {{ $guia['camino'] === 'ninguno' ? 'nada' : '' }}">
                    @if ($guia['camino'] !== 'ninguno')
                        {{-- «Sugerido» y no «te toca»: esto orienta, no manda.
                             Quien llega puede elegir otro camino igual. --}}
                        <p class="rotulo" style="margin-bottom:.2rem">Sugerido</p>
                        <p class="cual"><a href="{{ $guia['url'] }}">{{ $guia['titulo'] }} →</a></p>
                    @endif
                    <p class="porque">{{ $guia['porque'] }}</p>
                    <p class="pie">Es una orientación; las tarjetas de arriba dicen qué hace cada camino. No es un chat: aquí solo se responde por dónde ir.</p>
                </div>
            @endif
        </section>
