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
        .cam-persona-foto { position: relative; aspect-ratio: 9 / 16; border-radius: .7rem; overflow: hidden; background: var(--cam-suave);
                            border: 1px solid var(--cam-borde); }
        .cam-persona-foto img { width: 100%; height: 100%; object-fit: cover; object-position: center top; transition: transform .2s; transform-origin: center top; }
        .cam-persona:hover img { transform: scale(1.04); }
        .cam-persona-hora { position: absolute; left: .35rem; bottom: .35rem; color: #fff; font-size: .72rem; font-weight: 600;
                            background: rgba(0,0,0,.55); padding: .12rem .45rem; border-radius: 999px; font-variant-numeric: tabular-nums; }
        .cam-persona-pie { font-size: .74rem; color: var(--cam-tenue); margin-top: .3rem; line-height: 1.3; }
        .cam-persona-num { position: absolute; left: .35rem; top: .35rem; color: #fff; font-size: .68rem; font-weight: 700;
                           background: rgba(0,0,0,.5); padding: .08rem .4rem; border-radius: 999px; font-variant-numeric: tabular-nums; }
        .cam-persona-volvio { position: absolute; right: .35rem; top: .35rem; color: #fff; font-size: .68rem; font-weight: 700;
                              background: rgba(37,99,235,.85); padding: .08rem .45rem; border-radius: 999px; }
        .cam-persona.elegible { cursor: pointer; }
        .cam-persona.elegida .cam-persona-foto { outline: 3px solid #2563eb; outline-offset: 2px; }
        .cam-persona-marca { position: absolute; inset: 0; display: grid; place-items: center; background: rgba(37,99,235,.28); }
        .cam-persona-marca span { width: 2rem; height: 2rem; border-radius: 999px; background: #2563eb; color: #fff; display: grid; place-items: center; font-weight: 700; }
        .cam-acciones { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
        .cam-btn { font-size: .82rem; font-weight: 600; padding: .4rem .8rem; border-radius: .55rem; border: 1px solid var(--cam-borde); cursor: pointer; }
        .cam-btn:hover { background: var(--cam-suave); }
        .cam-btn-fuerte { background: #2563eb; color: #fff; border-color: #2563eb; }
        .cam-btn-fuerte:hover { background: #1d4ed8; }
        .cam-btn:disabled { opacity: .5; cursor: not-allowed; }
        .cam-uniendo { display: flex; align-items: center; justify-content: space-between; gap: .75rem; flex-wrap: wrap; margin-bottom: .9rem;
                       padding: .6rem .8rem; border-radius: .7rem; background: rgba(37,99,235,.08); border: 1px solid rgba(37,99,235,.3); font-size: .85rem; }
        .cam-vacio { border: 1px dashed var(--cam-borde); border-radius: .9rem; padding: 2rem 1rem; text-align: center; color: var(--cam-tenue); font-size: .9rem; }
        .cam-nota { font-size: .78rem; color: var(--cam-tenue); margin-top: .9rem; }

        /* Foto en grande */
        .cam-grande { position: fixed; inset: 0; z-index: 60; background: rgba(0,0,0,.85); display: grid; place-items: center; padding: 1.5rem; cursor: zoom-out; }
        .cam-grande img { max-width: min(92vw, 720px); max-height: 82vh; border-radius: .8rem; }
        .cam-grande p { color: rgba(255,255,255,.85); text-align: center; font-size: .85rem; margin-top: .6rem; }
        .cam-grande-otras { display: flex; gap: .4rem; justify-content: center; flex-wrap: wrap; margin-top: .6rem; }
        .cam-grande-otras img { width: 3.2rem; height: 4.2rem; object-fit: cover; border-radius: .4rem; cursor: pointer; opacity: .75; border: 2px solid transparent; }
        .cam-grande-otras img:hover, .cam-grande-otras img.actual { opacity: 1; border-color: #fff; }
        .cam-grande-quitar { text-align: center; margin-top: .7rem; color: rgba(255,255,255,.75); font-size: .8rem; cursor: default; }
        .cam-grande-quitar .cam-btn { background: rgba(255,255,255,.1); color: #fff; border-color: rgba(255,255,255,.4); }
        .cam-grande-quitar .cam-btn:hover { background: rgba(255,255,255,.2); }
        .cam-grande-quitar .cam-btn-fuerte { background: #2563eb; border-color: #2563eb; }
        .cam-grande-quitar .cam-btn-fuerte:hover { background: #1d4ed8; }
        .cam-grande-quitar .cam-btn-quitar:hover { background: rgba(220,38,38,.8); border-color: transparent; }
        .cam-grande-mini { position: relative; display: inline-block; }
        .cam-grande-descartar { margin-top: .9rem; }
        .cam-grande-foto { position: relative; width: fit-content; margin: 0 auto; }
        .cam-grande-foto img { display: block; }
        .cam-grande-marco { position: absolute; border: 3px solid #facc15; border-radius: .4rem; box-shadow: 0 0 0 2px rgba(0,0,0,.5); pointer-events: none; }
        .cam-grande-nav { position: fixed; top: 50%; transform: translateY(-50%); width: 3rem; height: 3rem; border-radius: 999px; border: 0;
                          background: rgba(255,255,255,.15); color: #fff; font-size: 2rem; line-height: 1; cursor: pointer; display: grid; place-items: center; z-index: 61; }
        .cam-grande-nav:hover { background: rgba(255,255,255,.3); }
        .cam-grande-nav.izq { left: 1rem; }
        .cam-grande-nav.der { right: 1rem; }
        .cam-grande-pos { color: rgba(255,255,255,.6); font-size: .75rem; text-align: center; margin-top: .2rem; }
        .cam-grande-mini.marcada img { opacity: .45; border-color: #ef4444; }
        .cam-grande-check { position: absolute; right: -.3rem; top: -.3rem; width: 1.35rem; height: 1.35rem; border-radius: 999px;
                            font-size: .72rem; font-weight: 700; color: transparent; background: rgba(0,0,0,.55); border: 2px solid #fff;
                            display: grid; place-items: center; cursor: pointer; padding: 0; }
        .cam-grande-check:hover { color: rgba(255,255,255,.7); }
        .cam-grande-mini.marcada .cam-grande-check { background: #ef4444; border-color: #ef4444; color: #fff; }
    </style>

    @php($camaras = $this->camaras())

    @if (empty($camaras))
        <div class="cam-vacio">
            No hay cámaras configuradas. Se agregan en el <code>.env</code> del servidor:<br>
            <code>CAMARA_1_URL=rtsp://usuario:clave@IP:554/stream2</code> y, si se quiere, <code>CAMARA_1_NOMBRE="Entrada"</code>.
        </div>
    @else
        @php($conteo = $this->conteo())
        <div class="cam" x-data="{ grande: null, uniendo: false, sel: [],
                    elegir(id) { this.sel = this.sel.includes(id) ? this.sel.filter(x => x !== id) : [...this.sel, id] },
                    unir() { $wire.unir(this.sel).then(() => { this.sel = []; this.uniendo = false }) },
                    marcar(id) {
                        const m = this.grande.marcadas;
                        this.grande.marcadas = m.includes(id) ? m.filter(x => x !== id) : [...m, id];
                    },
                    {{-- Huellas marcadas (la foto del día marcada es «dia» si no es de ninguna huella). --}}
                    abrir(el) { if (el.dataset.grande) this.grande = JSON.parse(el.dataset.grande) },
                    {{-- Anterior / siguiente: en el orden de las tarjetas, leídas en el momento (siempre al día). --}}
                    tarjetas() { return [...this.$root.querySelectorAll('.cam-persona[data-grande]')] },
                    posicion() { return this.tarjetas().findIndex(e => JSON.parse(e.dataset.grande).visita === this.grande.visita) },
                    ir(paso) {
                        const t = this.tarjetas(), i = this.posicion() + paso;
                        if (i >= 0 && i < t.length) this.abrir(t[i]);
                    },
                    {{-- Tras un cambio la página se rehace: se vuelve a abrir la misma persona con sus
                         fotos al día, o la que quedó en su lugar si ya no está. Solo se cierra a mano. --}}
                    seguir(visita, i) {
                        this.$nextTick(() => {
                            const t = this.tarjetas();
                            const misma = t.find(e => JSON.parse(e.dataset.grande).visita === visita);
                            if (misma) return this.abrir(misma);
                            t.length ? this.abrir(t[Math.min(i, t.length - 1)]) : this.grande = null;
                        })
                    },
                    descartar() {
                        if (!confirm('¿Quitar la n.º ' + this.grande.persona + '? Se borra con todas sus fotos y lo que se le parezca deja de contarse.')) return;
                        const i = this.posicion();
                        $wire.noEsPersona(this.grande.persona).then(ok => { if (ok) this.seguir(null, i) })
                    },
                    huellasMarcadas() { return this.grande.marcadas.filter(x => x !== 'dia') },
                    {{-- Si tiene huellas, le tiene que quedar al menos una. --}}
                    sePuede() { return this.grande.huellas === 0 || this.huellasMarcadas().length < this.grande.huellas },
                    {{-- quitarFotos o separarFotos: las marcadas se van de esta persona. --}}
                    sacar(accion) {
                        $wire[accion](this.grande.visita, this.huellasMarcadas(), this.grande.marcadas.includes('dia'))
                            .then(ok => { if (ok) this.seguir(this.grande.visita, this.posicion()) })
                    } }"
             x-on:keydown.escape.window="grande = null; uniendo = false; sel = []"
             x-on:keydown.arrow-left.window="grande && ir(-1)" x-on:keydown.arrow-right.window="grande && ir(1)">

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
                            @php($volvieron = collect($conteo['personas'])->where('volvio', true)->count())
                            <div class="cam-cifra-sub">reconocidas por el rostro{{ $volvieron ? ' · ' . $volvieron . ' ya habían venido' : '' }}</div>
                        </div>
                        <div class="cam-cifra">
                            <div class="cam-cifra-et">Máximo a la vez · {{ $this->dia }}</div>
                            <div class="cam-cifra-num">{{ $dia->maximo_a_la_vez ?? 0 }}</div>
                            <div class="cam-cifra-sub">
                                {{ ($dia?->maximo_at) ? 'a las ' . \Illuminate\Support\Carbon::parse($dia->maximo_at)->timezone(config('fabos.lab.timezone'))->format('g:i a') : 'sin personas todavía' }}
                            </div>
                        </div>
                        <div class="cam-cifra">
                            <div class="cam-cifra-et">Recordadas · {{ $conteo['dias_de_personas'] }} días</div>
                            <div class="cam-cifra-num">{{ $conteo['recordadas'] }}</div>
                            <div class="cam-cifra-sub">personas distintas en el último mes, sin nombre</div>
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
                        <div class="cam-acciones">
                            @if (count($conteo['personas']) > 1)
                                <button type="button" class="cam-btn" x-show="!uniendo" x-on:click="uniendo = true; sel = []">Unir fotos</button>
                            @endif
                            <div class="cam-pestanas" role="group">
                                <button type="button" wire:click="verDia('hoy')" aria-pressed="{{ $this->dia === 'hoy' ? 'true' : 'false' }}">Hoy</button>
                                <button type="button" wire:click="verDia('ayer')" aria-pressed="{{ $this->dia === 'ayer' ? 'true' : 'false' }}">Ayer</button>
                            </div>
                        </div>
                    </div>

                    <div class="cam-uniendo" x-show="uniendo" x-cloak>
                        <span x-text="sel.length < 2 ? 'Toca las fotos que son la misma persona (al menos dos).' : sel.length + ' fotos elegidas: quedarán como una sola persona.'"></span>
                        <span class="cam-acciones">
                            <button type="button" class="cam-btn cam-btn-fuerte" x-bind:disabled="sel.length < 2" x-on:click="unir()">Son la misma persona</button>
                            <button type="button" class="cam-btn" x-on:click="uniendo = false; sel = []">Cancelar</button>
                        </span>
                    </div>

                    @if (empty($conteo['personas']))
                        <div class="cam-vacio">
                            {{ $this->dia === 'ayer' ? 'Ayer no se contó a nadie.' : 'Todavía no se ha contado a nadie hoy.' }}
                        </div>
                    @else
                        <div class="cam-personas">
                            @foreach ($conteo['personas'] as $p)
                                @php($texto = 'N.º ' . $p['persona'] . ' · llegó ' . $p['llego'] . ($p['visto'] !== $p['llego'] ? ' · vista por última vez ' . $p['visto'] : '') . ($p['camara'] && count($camaras) > 1 ? ' · ' . $p['camara'] : '') . ($p['volvio'] ? ' · la conocemos desde el ' . $p['desde'] . ', ' . $p['dias'] . ' días' : ''))
                                @php($grande = $p['foto'] ? ['src' => $p['foto'], 'visita' => $p['visita'], 'persona' => $p['persona'], 'marcadas' => [], 'texto' => $texto, 'huellas' => $p['huellas'],
                                    'marco' => $p['marco'], 'otras' => array_merge([['src' => $p['foto'], 'clave' => $p['foto_huella'] ?? 'dia', 'marco' => $p['marco']]], array_map(fn ($o) => ['src' => $o['src'], 'clave' => $o['huella'], 'marco' => $o['marco']], $p['otras']))] : null)
                                <button type="button" class="cam-persona" wire:key="persona-{{ $p['persona'] }}"
                                        x-bind:class="{ 'elegible': uniendo, 'elegida': sel.includes({{ $p['persona'] }}) }"
                                        @if ($grande) data-grande="{{ json_encode($grande) }}" @endif
                                        x-on:click="uniendo ? elegir({{ $p['persona'] }}) : abrir($el)">
                                    <div class="cam-persona-foto">
                                        @if ($p['foto'])
                                            <img src="{{ $p['foto'] }}" alt="Persona contada" loading="lazy">
                                        @endif
                                        <span class="cam-persona-num">{{ $p['persona'] }}</span>
                                        @if ($p['volvio'])
                                            <span class="cam-persona-volvio" title="La conocemos desde el {{ $p['desde'] }}">volvió</span>
                                        @endif
                                        <span class="cam-persona-hora">{{ $p['llego'] }}</span>
                                        <span class="cam-persona-marca" x-show="sel.includes({{ $p['persona'] }})" x-cloak><span>✓</span></span>
                                    </div>
                                    <div class="cam-persona-pie">
                                        {{ $p['visto'] !== $p['llego'] ? 'hasta ' . $p['visto'] : 'vista una vez' }}
                                        @if ($p['volvio'])
                                            <br>{{ $p['dias'] }} días desde el {{ $p['desde'] }}
                                        @endif
                                        @if ($p['camara'] && count($camaras) > 1)
                                            <br>{{ $p['camara'] }}
                                        @endif
                                    </div>
                                </button>
                            @endforeach
                        </div>
                    @endif

                    <p class="cam-nota">
                        Cada persona es solo un número: el sistema no sabe quién es. Si dos fotos son la misma persona,
                        únelas: sus huellas quedan juntas y la próxima vez se la reconoce mejor. Quien no vuelve en
                        {{ $conteo['dias_de_personas'] }} días se borra con sus huellas y sus fotos; de cada día quedan los totales.
                    </p>
                </x-filament::section>
            @endif

            <template x-if="grande">
                <div class="cam-grande" x-on:click="grande = null">
                    <button type="button" class="cam-grande-nav izq" x-on:click.stop="ir(-1)" x-show="posicion() > 0" aria-label="Persona anterior">‹</button>
                    <button type="button" class="cam-grande-nav der" x-on:click.stop="ir(1)" x-show="posicion() < tarjetas().length - 1" aria-label="Persona siguiente">›</button>
                    <div>
                        {{-- El recuadro marca el rostro del que salió esta huella, por si en la foto hay varias personas. --}}
                        <div class="cam-grande-foto">
                            <img x-bind:src="grande.src" alt="Persona contada">
                            <template x-if="grande.marco && grande.marco.ancho">
                                <span class="cam-grande-marco" x-bind:style="`left:${grande.marco.x * 100}%;top:${grande.marco.y * 100}%;width:${grande.marco.ancho * 100}%;height:${grande.marco.alto * 100}%`"></span>
                            </template>
                        </div>
                        <p x-text="grande.texto"></p>
                        <div class="cam-grande-pos" x-text="(posicion() + 1) + ' de ' + tarjetas().length + ' · flechas ← → del teclado'"></div>
                        <div class="cam-grande-otras">
                            <template x-for="o in grande.otras" :key="o.src">
                                <span class="cam-grande-mini" x-bind:class="grande.marcadas.includes(o.clave) && 'marcada'">
                                    <img x-bind:src="o.src" x-bind:class="o.src === grande.src && 'actual'" x-on:click.stop="grande.src = o.src; grande.marco = o.marco" alt="Otra foto de la misma persona">
                                    <button type="button" class="cam-grande-check" x-on:click.stop="marcar(o.clave)"
                                            x-bind:aria-pressed="grande.marcadas.includes(o.clave)" title="Marcar: no es esta persona">✓</button>
                                </span>
                            </template>
                        </div>
                        <div class="cam-grande-quitar" x-on:click.stop>
                            <template x-if="grande.marcadas.length === 0">
                                <span>Marca con ✓ las fotos que no son de esta persona (también la primera, la del día).</span>
                            </template>
                            <template x-if="grande.marcadas.length > 0 && !sePuede()">
                                <span>Deja sin marcar al menos una: es con la que se la sigue reconociendo.</span>
                            </template>
                            <template x-if="grande.marcadas.length > 0 && sePuede()">
                                <span class="cam-acciones" style="justify-content: center">
                                    <button type="button" class="cam-btn cam-btn-fuerte" x-on:click="sacar('separarFotos')"
                                            x-text="grande.marcadas.length === 1 ? 'Es otra persona' : 'Son otra persona (las ' + grande.marcadas.length + ' juntas)'"></button>
                                    <button type="button" class="cam-btn cam-btn-quitar" x-on:click="sacar('quitarFotos')"
                                            x-text="'Quitar ' + (grande.marcadas.length === 1 ? 'la foto' : 'las ' + grande.marcadas.length + ' fotos')"></button>
                                    <button type="button" class="cam-btn" x-on:click="grande.marcadas = []">Desmarcar</button>
                                </span>
                            </template>
                            <div class="cam-grande-descartar">
                                <button type="button" class="cam-btn cam-btn-quitar"
                                        x-on:click="descartar()">
                                    No es una persona: quitarla
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    @endif
</x-filament-panels::page>
