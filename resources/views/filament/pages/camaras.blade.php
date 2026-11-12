<x-filament-panels::page>
    {{-- Estilos propios: Filament trae su CSS precompilado y las utilidades de
         Tailwind que él no usa no existen en producción. --}}
    <style>
        .cam { --cam-borde: rgba(120,120,130,.22); --cam-suave: rgba(120,120,130,.08); --cam-tenue: rgba(100,100,110,.85);
               --cam-mal: #dc2626; display: grid; gap: 1.5rem; }
        .dark .cam { --cam-borde: rgba(255,255,255,.1); --cam-suave: rgba(255,255,255,.04); --cam-tenue: rgba(220,220,230,.6); --cam-mal: #f87171; }

        /* Cifras */
        .cam-cifras { display: grid; gap: .75rem; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); }
        .cam-cifra { border: 1px solid var(--cam-borde); border-radius: .9rem; padding: 1rem 1.15rem; background: var(--cam-suave); }
        .cam-cifra-et { font-size: .72rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--cam-tenue); }
        .cam-cifra-num { font-size: 2.1rem; font-weight: 700; line-height: 1.1; margin-top: .25rem; font-variant-numeric: tabular-nums; }
        .cam-cifra-sub { font-size: .8rem; color: var(--cam-tenue); margin-top: .2rem; }
        .cam-cifra-lista { margin-top: .35rem; font-size: .82rem; display: grid; gap: .15rem; }
        .cam-cifra-lista span { color: var(--cam-tenue); }
        .cam-cifra-lista .cam-error, .cam-error { color: var(--cam-mal); }

        /* Rejilla de cámaras: una sola ocupa el ancho, dos van lado a lado, más se reparten */
        .cam-rejilla { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(min(100%, 380px), 1fr)); }
        .cam-rejilla[data-n="1"], .cam-rejilla.cam-enfocada { grid-template-columns: minmax(0, 1fr); }
        .cam-rejilla[data-n="2"] { grid-template-columns: repeat(auto-fit, minmax(min(100%, 420px), 1fr)); }
        .cam-tile { position: relative; border-radius: .9rem; overflow: hidden; background: #0b0d10; aspect-ratio: 16 / 9;
                    box-shadow: 0 1px 2px rgba(0,0,0,.08), 0 8px 24px -12px rgba(0,0,0,.35); }
        .cam-tile img { width: 100%; height: 100%; object-fit: contain; display: block; }
        .cam-tile:fullscreen { border-radius: 0; aspect-ratio: auto; }
        .cam-sobre { position: absolute; inset: 0; pointer-events: none; display: flex; flex-direction: column; justify-content: space-between;
                     padding: .7rem; background: linear-gradient(to bottom, rgba(0,0,0,.45), transparent 28%, transparent 72%, rgba(0,0,0,.45)); }
        .cam-fila { display: flex; align-items: center; justify-content: space-between; gap: .5rem; }
        .cam-pastilla { display: inline-flex; align-items: center; gap: .45rem; color: #fff; font-size: .8rem; font-weight: 600;
                        background: rgba(0,0,0,.45); backdrop-filter: blur(6px); padding: .3rem .65rem; border-radius: 999px; }
        .cam-punto { width: .5rem; height: .5rem; border-radius: 999px; background: #ef4444; }
        .cam-punto.vivo { background: #22c55e; animation: cam-latido 2s infinite; }
        @keyframes cam-latido { 0% { box-shadow: 0 0 0 0 rgba(34,197,94,.7); } 70% { box-shadow: 0 0 0 7px rgba(34,197,94,0); } 100% { box-shadow: 0 0 0 0 rgba(34,197,94,0); } }
        .cam-botones { display: flex; gap: .35rem; pointer-events: auto; }
        .cam-boton { color: #fff; background: rgba(0,0,0,.45); backdrop-filter: blur(6px); border-radius: .55rem; padding: .35rem;
                     display: inline-flex; cursor: pointer; border: 0; opacity: .85; }
        .cam-boton:hover { opacity: 1; background: rgba(0,0,0,.65); }
        .cam-boton svg { width: 1.05rem; height: 1.05rem; }
        .cam-hora { color: rgba(255,255,255,.85); font-size: .75rem; font-variant-numeric: tabular-nums; }
        .cam-sinsenal { position: absolute; inset: 0; display: grid; place-items: center; color: rgba(255,255,255,.7); font-size: .9rem; }

        /* Personas del día */
        .cam-cab { display: flex; align-items: flex-end; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: .9rem; }
        .cam-cab h2 { font-size: 1.05rem; font-weight: 700; margin: 0; }
        .cam-cab p { font-size: .82rem; color: var(--cam-tenue); margin: .15rem 0 0; }
        .cam-pestanas { display: inline-flex; border: 1px solid var(--cam-borde); border-radius: .6rem; padding: .2rem; gap: .2rem; }
        .cam-pestanas button { font-size: .82rem; font-weight: 600; padding: .3rem .8rem; border-radius: .45rem; color: var(--cam-tenue); }
        .cam-pestanas button[aria-pressed="true"] { background: var(--cam-suave); color: inherit; box-shadow: inset 0 0 0 1px var(--cam-borde); }
        .cam-personas { display: grid; gap: .75rem; grid-template-columns: repeat(auto-fill, minmax(118px, 1fr)); }
        .cam-persona { border: 0; padding: 0; background: none; text-align: left; cursor: zoom-in; }
        .cam-persona-foto { position: relative; aspect-ratio: 3 / 4; border-radius: .7rem; overflow: hidden; background: var(--cam-suave);
                            border: 1px solid var(--cam-borde); }
        .cam-persona-foto img { width: 100%; height: 100%; object-fit: cover; transition: transform .2s; }
        .cam-persona:hover img { transform: scale(1.04); }
        .cam-persona-hora { position: absolute; left: .35rem; bottom: .35rem; color: #fff; font-size: .72rem; font-weight: 600;
                            background: rgba(0,0,0,.55); padding: .12rem .45rem; border-radius: 999px; font-variant-numeric: tabular-nums; }
        .cam-persona-pie { font-size: .74rem; color: var(--cam-tenue); margin-top: .3rem; line-height: 1.3; }
        .cam-vacio { border: 1px dashed var(--cam-borde); border-radius: .9rem; padding: 2rem 1rem; text-align: center; color: var(--cam-tenue); font-size: .9rem; }
        .cam-nota { font-size: .78rem; color: var(--cam-tenue); margin-top: .9rem; }

        /* Foto en grande */
        .cam-grande { position: fixed; inset: 0; z-index: 60; background: rgba(0,0,0,.85); display: grid; place-items: center; padding: 1.5rem; cursor: zoom-out; }
        .cam-grande img { max-width: min(92vw, 720px); max-height: 82vh; border-radius: .8rem; }
        .cam-grande p { color: rgba(255,255,255,.85); text-align: center; font-size: .85rem; margin-top: .6rem; }
    </style>

    @php($camaras = $this->camaras())

    @if (empty($camaras))
        <div class="cam-vacio">
            No hay cámaras configuradas. Se agregan en el <code>.env</code> del servidor:<br>
            <code>CAMARA_1_URL=rtsp://usuario:clave@IP:554/stream2</code> y, si se quiere, <code>CAMARA_1_NOMBRE="Entrada"</code>.
        </div>
    @else
        @php($conteo = $this->conteo())
        <div class="cam" x-data="{ grande: null }" x-on:keydown.escape.window="grande = null">

            {{-- Cifras: se repintan cada 30 s; la cámara cuenta cada minuto. --}}
            @if ($conteo['activo'])
                <div wire:poll.30s>
                    @php($dia = $conteo['dia'])
                    @php($ahora = collect($conteo['camaras'])->sum(fn ($c) => $c['lectura']['personas'] ?? 0))
                    <div class="cam-cifras">
                        <div class="cam-cifra">
                            <div class="cam-cifra-et">Ahora en cámara</div>
                            <div class="cam-cifra-num">{{ $ahora }}</div>
                            @if (count($camaras) > 1)
                                <div class="cam-cifra-lista">
                                    @foreach ($camaras as $c)
                                        @php($l = $conteo['camaras'][$c['numero']] ?? [])
                                        <div>
                                            {{ $c['nombre'] }}:
                                            @if ($l['lectura'] ?? null)
                                                <strong>{{ $l['lectura']['personas'] }}</strong>
                                            @elseif ($l['error'] ?? null)
                                                <span class="cam-error">{{ $l['error'] }}</span>
                                            @else
                                                <span>esperando lectura</span>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                @php($l = $conteo['camaras'][$camaras[0]['numero']] ?? [])
                                <div class="cam-cifra-sub">
                                    @if ($l['lectura'] ?? null)
                                        {{ $l['lectura']['sin_identificar'] > 0 ? $l['lectura']['sin_identificar'] . ' sin rostro visible' : 'según la última lectura' }}
                                    @elseif ($l['error'] ?? null)
                                        <span class="cam-error">{{ $l['error'] }}</span>
                                    @else
                                        esperando la primera lectura
                                    @endif
                                </div>
                            @endif
                        </div>
                        <div class="cam-cifra">
                            <div class="cam-cifra-et">Personas distintas · {{ $this->dia }}</div>
                            <div class="cam-cifra-num">{{ $dia->personas_distintas ?? 0 }}</div>
                            <div class="cam-cifra-sub">reconocidas por el rostro</div>
                        </div>
                        <div class="cam-cifra">
                            <div class="cam-cifra-et">Máximo a la vez · {{ $this->dia }}</div>
                            <div class="cam-cifra-num">{{ $dia->maximo_a_la_vez ?? 0 }}</div>
                            <div class="cam-cifra-sub">
                                {{ ($dia?->maximo_at) ? 'a las ' . \Illuminate\Support\Carbon::parse($dia->maximo_at)->timezone(config('fabos.lab.timezone'))->format('g:i a') : 'sin personas todavía' }}
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Las cámaras. Cada cuadro pide el siguiente apenas llega, con dos
                 segundos de pausa; con la pestaña oculta no pide nada. --}}
            <div wire:ignore x-data="{ enfocada: null }">
                <div class="cam-rejilla" data-n="{{ count($camaras) }}" x-bind:class="enfocada && 'cam-enfocada'">
                    @foreach ($camaras as $c)
                        <div class="cam-tile" x-ref="tile{{ $c['numero'] }}"
                             x-show="!enfocada || enfocada === {{ $c['numero'] }}"
                             x-data="{
                                base: @js($c['foto']), src: '', vivo: false, hora: '', espera: null,
                                pedir() {
                                    clearTimeout(this.espera);
                                    if (document.hidden) { this.espera = setTimeout(() => this.pedir(), 2000); return; }
                                    this.src = this.base + '?t=' + Date.now();
                                },
                                llego() { this.vivo = true; this.hora = new Date().toLocaleTimeString('es-CO'); this.espera = setTimeout(() => this.pedir(), 2000); },
                                fallo() { this.vivo = false; this.espera = setTimeout(() => this.pedir(), 5000); },
                             }"
                             x-init="pedir()">
                            <img x-bind:src="src" x-show="vivo" x-on:load="llego()" x-on:error="fallo()" alt="{{ $c['nombre'] }}">
                            <div class="cam-sinsenal" x-show="!vivo" x-text="src ? 'Sin señal · reintentando…' : 'Conectando…'"></div>
                            <div class="cam-sobre">
                                <div class="cam-fila">
                                    <span class="cam-pastilla"><span class="cam-punto" x-bind:class="vivo && 'vivo'"></span>{{ $c['nombre'] }}</span>
                                    <span class="cam-botones">
                                        @if (count($camaras) > 1)
                                            <button type="button" class="cam-boton"
                                                    x-on:click="enfocada = enfocada ? null : {{ $c['numero'] }}"
                                                    x-bind:title="enfocada ? 'Ver todas' : 'Ver solo esta'">
                                                <svg x-show="!enfocada" viewBox="0 0 20 20" fill="currentColor"><path d="M3 4a1 1 0 0 1 1-1h4a1 1 0 0 1 0 2H6.4l3.3 3.3a1 1 0 0 1-1.4 1.4L5 6.4V8a1 1 0 0 1-2 0V4Zm14 12a1 1 0 0 1-1 1h-4a1 1 0 1 1 0-2h1.6l-3.3-3.3a1 1 0 0 1 1.4-1.4l3.3 3.3V12a1 1 0 1 1 2 0v4Z"/></svg>
                                                <svg x-show="enfocada" viewBox="0 0 20 20" fill="currentColor"><path d="M3 3h6v6H3V3Zm8 0h6v6h-6V3ZM3 11h6v6H3v-6Zm8 0h6v6h-6v-6Z"/></svg>
                                            </button>
                                        @endif
                                        <button type="button" class="cam-boton" title="Pantalla completa"
                                                x-on:click="document.fullscreenElement ? document.exitFullscreen() : $refs.tile{{ $c['numero'] }}.requestFullscreen()">
                                            <svg viewBox="0 0 20 20" fill="currentColor"><path d="M3 4a1 1 0 0 1 1-1h3a1 1 0 0 1 0 2H5v2a1 1 0 0 1-2 0V4Zm10-1h3a1 1 0 0 1 1 1v3a1 1 0 1 1-2 0V5h-2a1 1 0 1 1 0-2ZM4 12a1 1 0 0 1 1 1v2h2a1 1 0 1 1 0 2H4a1 1 0 0 1-1-1v-3a1 1 0 0 1 1-1Zm12 0a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-3a1 1 0 1 1 0-2h2v-2a1 1 0 0 1 1-1Z"/></svg>
                                        </button>
                                    </span>
                                </div>
                                <div class="cam-fila" style="justify-content: flex-end;">
                                    <span class="cam-hora" x-text="vivo ? hora : ''"></span>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Personas del día --}}
            @if ($conteo['activo'])
                <x-filament::section>
                    <div class="cam-cab">
                        <div>
                            <h2>Personas {{ $this->dia === 'ayer' ? 'de ayer' : 'de hoy' }}</h2>
                            <p>{{ ucfirst($conteo['fecha']) }} · {{ count($conteo['personas']) }} {{ count($conteo['personas']) === 1 ? 'persona' : 'personas' }}</p>
                        </div>
                        <div class="cam-pestanas" role="group">
                            <button type="button" wire:click="verDia('hoy')" aria-pressed="{{ $this->dia === 'hoy' ? 'true' : 'false' }}">Hoy</button>
                            <button type="button" wire:click="verDia('ayer')" aria-pressed="{{ $this->dia === 'ayer' ? 'true' : 'false' }}">Ayer</button>
                        </div>
                    </div>

                    @if (empty($conteo['personas']))
                        <div class="cam-vacio">
                            {{ $this->dia === 'ayer' ? 'Ayer no se contó a nadie.' : 'Todavía no se ha contado a nadie hoy.' }}
                        </div>
                    @else
                        <div class="cam-personas">
                            @foreach ($conteo['personas'] as $p)
                                @php($texto = 'Llegó ' . $p['llego'] . ($p['visto'] !== $p['llego'] ? ' · vista por última vez ' . $p['visto'] : '') . ($p['camara'] && count($camaras) > 1 ? ' · ' . $p['camara'] : ''))
                                <button type="button" class="cam-persona"
                                        @if ($p['foto']) x-on:click="grande = { src: @js($p['foto']), texto: @js($texto) }" @endif>
                                    <div class="cam-persona-foto">
                                        @if ($p['foto'])
                                            <img src="{{ $p['foto'] }}" alt="Persona contada" loading="lazy">
                                        @endif
                                        <span class="cam-persona-hora">{{ $p['llego'] }}</span>
                                    </div>
                                    <div class="cam-persona-pie">
                                        {{ $p['visto'] !== $p['llego'] ? 'hasta ' . $p['visto'] : 'vista una vez' }}
                                        @if ($p['camara'] && count($camaras) > 1)
                                            <br>{{ $p['camara'] }}
                                        @endif
                                    </div>
                                </button>
                            @endforeach
                        </div>
                    @endif

                    <p class="cam-nota">
                        Un recorte por persona, para reconocerla mirándola: el sistema no sabe quién es.
                        Lo de cada día se conserva hasta que termina el día siguiente; después quedan solo los totales.
                    </p>
                </x-filament::section>
            @endif

            <template x-if="grande">
                <div class="cam-grande" x-on:click="grande = null">
                    <div>
                        <img x-bind:src="grande.src" alt="Persona contada">
                        <p x-text="grande.texto"></p>
                    </div>
                </div>
            </template>
        </div>
    @endif
</x-filament-panels::page>
