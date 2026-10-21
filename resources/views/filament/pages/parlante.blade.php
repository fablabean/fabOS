<x-filament-panels::page>
    {{-- La agenda se vuelve a pedir cada cinco minutos: un horario nuevo o un
         audio recién generado entran sin recargar. Va en un JSON aparte y no
         dentro de x-data porque Alpine no relee x-data cuando Livewire
         repinta; el reloj lo lee del JSON en cada vuelta. --}}
    <div wire:poll.300s>
        <script type="application/json" id="agenda-del-parlante">@json($this->eventos())</script>
    </div>

    {{-- La música, igual: aparte y leída una sola vez. Si fuera en x-data,
         cualquier cambio —el volumen, otra lista— haría que Alpine reiniciara
         la pantalla al repintar: el botón volvía a «Encender» y el Spotify de
         antes seguía sonando por su cuenta. --}}
    <script type="application/json" id="musica-del-parlante-datos" wire:ignore>@json($this->musica())</script>

    <div x-data="parlanteDelLaboratorio(JSON.parse(document.getElementById('musica-del-parlante-datos')?.textContent || 'null'))" x-init="iniciar()" class="space-y-6"
         x-on:parlante-cambiar-lista.window="cambiarLista($event.detail)">
        @if (session('spotify_aviso'))
            <x-filament::section>
                <p class="text-sm font-medium">{{ session('spotify_aviso') }}</p>
            </x-filament::section>
        @endif

        <x-filament::section>
            <div class="flex flex-wrap items-center gap-4">
                <template x-if="! encendido">
                    <x-filament::button size="lg" icon="heroicon-o-speaker-wave" x-on:click="encender()">
                        Encender el parlante
                    </x-filament::button>
                </template>
                <template x-if="encendido">
                    <x-filament::button size="lg" color="gray" icon="heroicon-o-speaker-x-mark" x-on:click="apagar()">
                        Apagar
                    </x-filament::button>
                </template>

                <div class="text-sm">
                    <p x-show="! encendido" class="text-gray-600 dark:text-gray-300">
                        Apagado: no suena nada. Los navegadores no dejan sonar una página hasta que alguien la toca; por eso hay que encenderlo con un clic cada vez que se abre.
                    </p>
                    <p x-show="encendido && ! sonando" x-cloak class="text-gray-600 dark:text-gray-300">
                        Encendido. Deja esta pestaña abierta y el equipo sin suspender: los anuncios suenan solos a su hora.
                    </p>
                    <p x-show="sonando" x-cloak class="font-medium text-success-600">
                        Sonando: <span x-text="sonando"></span>
                    </p>
                </div>
            </div>
        </x-filament::section>

        {{-- La música de fondo: una lista de YouTube, que se calla sola
             mientras habla un anuncio. --}}
        @if ($this->musica())
            <x-filament::section>
                <x-slot name="heading">Música de fondo</x-slot>
                <x-slot name="description">Suena mientras el parlante está encendido y baja de volumen durante cada anuncio; una emergencia la apaga. Se configura en «Música», arriba.</x-slot>

                {{-- Con estilos propios: el panel no trae las clases de utilidad
                     que no usa él mismo, y la sección salía desarmada. --}}
                <style>
                    .pl-musica{display:flex;flex-wrap:wrap;gap:1.5rem;align-items:flex-start}
                    .pl-spotify{display:flex;align-items:center;gap:.75rem;padding:.75rem 1rem;border-radius:.6rem;background:#191414;color:#fff;width:15rem;flex:none}
                    .pl-spotify b{display:block;font-size:.9rem}
                    .pl-spotify small{display:block;font-size:.78rem;opacity:.75}
                    .pl-video{overflow:hidden;border-radius:.6rem;width:320px;height:180px;background:#000;flex:none}
                    .pl-controles{display:flex;flex-direction:column;gap:.7rem;font-size:.9rem;min-width:16rem;flex:1}
                    .pl-fila{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem}
                    .pl-gris{color:#6b7280}
                    .pl-estado{font-weight:500}
                    .pl-select{border:1px solid #d1d5db;border-radius:.5rem;padding:.4rem 2rem .4rem .6rem;font-size:.88rem;background-color:transparent;min-width:13rem}
                    .dark .pl-select{border-color:rgba(255,255,255,.15)}
                    .pl-listas{border-top:1px solid rgba(128,128,128,.2);padding-top:.7rem}
                    .pl-volumen{flex:1;min-width:8rem;max-width:16rem;accent-color:rgb(var(--primary-600,217 119 6))}
                    .pl-rotulo{font-size:.72rem;letter-spacing:.1em;text-transform:uppercase;color:#6b7280;margin-bottom:.35rem}
                </style>

                <div class="pl-musica">
                    @if ($this->musica()['fuente'] === 'spotify')
                        <div class="pl-spotify">
                            <svg viewBox="0 0 24 24" width="30" height="30" aria-hidden="true" style="flex:none"><circle cx="12" cy="12" r="12" fill="#1DB954"/><path d="M17.3 16.6a.75.75 0 0 1-1 .25c-2.8-1.7-6.3-2.1-10.4-1.1a.75.75 0 1 1-.33-1.46c4.5-1 8.4-.6 11.5 1.3.35.2.46.66.24 1Zm1.4-3.2a.94.94 0 0 1-1.3.3c-3.2-2-8.1-2.5-11.9-1.4a.94.94 0 1 1-.54-1.8c4.3-1.3 9.7-.7 13.4 1.6.44.27.58.85.31 1.3Zm.13-3.3C15 7.8 8.6 7.6 4.9 8.7a1.1 1.1 0 1 1-.66-2.15c4.3-1.3 11.4-1.05 15.8 1.6a1.1 1.1 0 0 1-1.15 1.94Z" fill="#191414"/></svg>
                            <div><b>Spotify</b><small>{{ \App\Support\SpotifyDelParlante::cuenta() }}</small></div>
                        </div>
                    @else
                        <div wire:ignore class="pl-video">
                            <div id="musica-del-parlante"></div>
                        </div>
                    @endif

                    <div class="pl-controles">
                        <label class="pl-fila">
                            <input type="checkbox" x-model="conMusica" x-on:change="cambiarMusica()">
                            Con música
                        </label>
                        <div class="pl-estado" x-text="estadoMusica"></div>
                        {{-- El volumen de la música, solo de ella: los anuncios y las
                             alertas suenan por su lado, a su propio volumen. --}}
                        <label class="pl-fila">
                            <span>Volumen de la música</span>
                            <input type="range" min="0" max="100" step="1" class="pl-volumen"
                                   :value="musica.volumen"
                                   x-on:input="cambiarVolumen(+$event.target.value)"
                                   x-on:change="$wire.guardarVolumen(musica.volumen)">
                            <span class="pl-gris"><span x-text="musica.volumen"></span> %</span>
                        </label>
                        <div class="pl-gris" x-text="musica.aleatoria ? 'En orden aleatorio.' : 'En orden.'"></div>

                        @if ($this->musica()['fuente'] === 'spotify')
                            <div class="pl-fila">
                                <x-filament::button size="sm" color="gray" icon="heroicon-o-forward" x-on:click="reproductor?.saltar?.()">
                                    Saltar la que suena
                                </x-filament::button>
                                <span class="pl-gris">Rocola: {{ route('rocola') }}</span>
                            </div>
                        @endif

                        {{-- Cambiar de lista en vivo: sin recargar ni apagar. --}}
                        <div class="pl-listas">
                            <div class="pl-rotulo">Lista</div>
                            @if ($this->listas() !== [])
                                <div class="pl-fila" x-data="{ elegida: @js($this->listaActual() ?? 0) }">
                                    <select x-model.number="elegida" class="pl-select">
                                        @foreach ($this->listas() as $i => $l)
                                            <option value="{{ $i }}">{{ $l['nombre'] }}</option>
                                        @endforeach
                                    </select>
                                    <x-filament::button size="sm" icon="heroicon-o-arrows-right-left" x-on:click="$wire.ponerLista(elegida)">
                                        Poner esta lista
                                    </x-filament::button>
                                </div>
                                @if ($this->listaActual() === null)
                                    <div class="pl-gris" style="margin-top:.35rem">Ahora suena la lista principal, que no está entre las guardadas.</div>
                                @endif
                            @else
                                <div class="pl-gris">
                                    Suena la lista principal. Para cambiar de lista aquí mismo, en vivo, guarda otras en
                                    «Música» (arriba) → «Otras listas a mano»; después recarga esta pantalla una vez.
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </x-filament::section>
        @endif

        {{-- Quién puso qué en la rocola: el equipo responde por lo que suena. --}}
        @if (($this->musica()['fuente'] ?? null) === 'spotify')
            <x-filament::section wire:poll.20s>
                <x-slot name="heading">Sonando y lo que viene</x-slot>
                <x-slot name="description">Con la hora aproximada de cada canción y quién la pidió en la rocola. Si alguna no va, «Saltar la que suena» cuando le toque.</x-slot>

                @include('rocola._estado', ['estado' => $this->estadoDeLaRocola()])

                <p class="mt-5 mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500">Pedidas en las últimas tres horas</p>
                @forelse ($this->pedidas() as $p)
                    <div class="flex items-center gap-3 py-1 text-sm">
                        @if ($p->portada_url)<img src="{{ $p->portada_url }}" alt="" width="32" height="32" class="rounded">@endif
                        <span class="flex-1"><strong>{{ $p->titulo }}</strong> · {{ $p->artista }}</span>
                        <span class="text-gray-500">{{ $p->user?->name }} · {{ $p->created_at->timezone(config('fabos.lab.timezone'))->format('H:i') }}</span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">Nadie ha pedido canciones en las últimas tres horas.</p>
                @endforelse
            </x-filament::section>
        @endif

        <x-filament::section>
            <x-slot name="heading">Lo que viene</x-slot>
            <x-slot name="description">De ahora hasta mañana a esta hora, en hora del laboratorio. Los horarios se ponen en cada anuncio, en «Anuncios con voz».</x-slot>

            <template x-if="proximos.length === 0">
                <p class="text-sm text-gray-500">No hay nada programado en las próximas 24 horas.</p>
            </template>

            <ul class="divide-y divide-gray-200 dark:divide-white/10">
                <template x-for="e in proximos" :key="e.clave">
                    <li class="flex items-center gap-4 py-2 text-sm">
                        <span class="w-24 font-mono" x-text="cuando(e)"></span>
                        <span class="flex-1" x-text="e.titulo"></span>
                        <span class="text-gray-500" x-show="e.repeticiones > 1"
                              x-text="e.repeticiones + ' veces, cada ' + e.pausa + ' s'"></span>
                        <span class="text-success-600" x-show="hechos[e.clave]">sonó</span>
                        <x-filament::link tag="button" icon="heroicon-o-play" x-on:click="lanzar(e)">Probar</x-filament::link>
                    </li>
                </template>
            </ul>
        </x-filament::section>
    </div>

    <script>
        function parlanteDelLaboratorio(musica) {
            const GRACIA = 90 * 1000;
            const MEMORIA = 'fabos-parlante-hechos';
            const CON_MUSICA = 'fabos-parlante-musica';

            return {
                musica: musica,
                reproductor: null,
                conMusica: true,
                hablando: false,
                deslizando: null,
                yaArranco: false,
                estadoMusica: 'Esperando a que se encienda el parlante.',
                encendido: false,
                sonando: null,
                proximos: [],
                hechos: {},
                cola: [],
                audio: null,

                iniciar() {
                    try { this.hechos = JSON.parse(localStorage.getItem(MEMORIA) || '{}'); } catch (e) { this.hechos = {}; }
                    try { this.conMusica = localStorage.getItem(CON_MUSICA) !== 'no'; } catch (e) {}
                    if (this.musica) this.prepararMusica();

                    // Los favoritos de la barra y el «Escuchar» de la lista
                    // suenan por otro reproductor: avisan, y la música se
                    // calla mientras hablan.
                    const aviso = (d) => {
                        if (! d) return;
                        if (d.sonando) this.bajarMusica(d.emergencia);
                        // Si la cola del Parlante sigue hablando, que no suba todavía.
                        else if (! this.sonando) this.subirMusica();
                    };
                    window.addEventListener('fabos-anuncio', (ev) => aviso(ev.detail));
                    // Y los de otras pestañas del mismo navegador.
                    if ('BroadcastChannel' in window) {
                        new BroadcastChannel('fabos-anuncio').addEventListener('message', (ev) => {
                            // Lo de esta misma pestaña ya llegó por el evento de arriba.
                            if (ev.data?.pestana !== window.fabosAnuncios?.pestana) aviso(ev.data);
                        });
                    }
                    this.vuelta();
                    // Cada segundo. En una pestaña de fondo el navegador lo
                    // frena a una vez por minuto, y por eso la gracia es de
                    // minuto y medio.
                    setInterval(() => this.vuelta(), 1000);
                },

                agenda() {
                    try { return JSON.parse(document.getElementById('agenda-del-parlante')?.textContent || '[]'); }
                    catch (e) { return []; }
                },

                vuelta() {
                    const ahora = Date.now();
                    const eventos = this.agenda();
                    this.proximos = eventos.filter((e) => e.t > ahora - GRACIA);

                    if (! this.encendido) return;

                    for (const e of eventos) {
                        if (ahora >= e.t && ahora - e.t < GRACIA && ! this.hechos[e.clave]) {
                            this.recordar(e.clave);
                            this.lanzar(e);
                        }
                    }
                },

                recordar(clave) {
                    this.hechos[clave] = Date.now();
                    // Se olvida lo de hace más de dos días: si no, la memoria
                    // del navegador crece sin fin.
                    const limite = Date.now() - 2 * 86400000;
                    for (const [c, t] of Object.entries(this.hechos)) if (t < limite) delete this.hechos[c];
                    try { localStorage.setItem(MEMORIA, JSON.stringify(this.hechos)); } catch (e) {}
                },

                encender() {
                    // El elemento se crea con el clic: es lo que el navegador
                    // acepta como permiso para sonar después sin otro clic.
                    this.audio = new Audio();
                    // Y el motor de audio que sube las alertas: también con el clic.
                    window.fabosVolumen?.despertar();
                    this.encendido = true;
                    this.reproductor?.activar?.();
                    this.sonarMusica();
                },

                // ---------------------------------------------- la música

                prepararMusica() {
                    if (this.musica.fuente === 'spotify') this.prepararSpotify();
                    else this.prepararYouTube();
                },

                /*
                 * Spotify, con su reproductor web: la pantalla se vuelve un
                 * dispositivo de la cuenta del laboratorio. Se envuelve para
                 * que hable como el de YouTube —volumen de 0 a 100, sonar,
                 * callar—, y así bajar en los anuncios y apagar en una
                 * emergencia es exactamente lo mismo con los dos.
                 */
                prepararSpotify() {
                    const m = this.musica;
                    const acceso = async () => {
                        const r = await fetch(m.acceso, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                        const d = await r.json().catch(() => ({}));
                        if (! r.ok || ! d.token) throw new Error(d.error || 'Sin acceso a Spotify.');
                        return d.token;
                    };
                    const api = async (metodo, ruta, cuerpo) => fetch('https://api.spotify.com/v1' + ruta, {
                        method: metodo,
                        headers: { Authorization: 'Bearer ' + await acceso(), 'Content-Type': 'application/json' },
                        body: cuerpo ? JSON.stringify(cuerpo) : undefined,
                    });

                    const crear = () => {
                        const player = new Spotify.Player({
                            name: m.nombre,
                            volume: m.volumen / 100,
                            getOAuthToken: (cb) => acceso().then(cb).catch((e) => { this.estadoMusica = e.message + ' Revisa la conexión en «Música».'; }),
                        });
                        let dispositivo = null, arranco = false, volumen = m.volumen;

                        const adaptador = {
                            activar: () => player.activateElement?.(),
                            saltar: () => player.nextTrack(),
                            // La lista nueva ya está en m.lista: se arranca de cero.
                            cambiarLista: (sonar) => { arranco = false; if (sonar) adaptador.playVideo(); },
                            setVolume: (v) => { volumen = v; player.setVolume(Math.max(0, Math.min(1, v / 100))); },
                            getVolume: () => volumen,
                            getPlaylist: () => [],
                            pauseVideo: () => player.pause(),
                            playVideo: async () => {
                                if (arranco) { player.resume(); return; }
                                arranco = true;
                                try {
                                    // En aleatorio, arranca en una canción al azar de la lista.
                                    let desde = 0;
                                    if (m.aleatoria) {
                                        const info = await (await api('GET', '/playlists/' + m.lista + '?fields=tracks.total')).json();
                                        desde = Math.floor(Math.random() * Math.max(1, info?.tracks?.total || 1));
                                    }
                                    const r = await api('PUT', '/me/player/play?device_id=' + dispositivo, {
                                        context_uri: 'spotify:playlist:' + m.lista, offset: { position: desde },
                                    });
                                    if (! r.ok && r.status !== 204) throw new Error('Spotify no empezó la lista (' + r.status + ').');
                                    await api('PUT', '/me/player/shuffle?state=' + (m.aleatoria ? 'true' : 'false') + '&device_id=' + dispositivo);
                                    await api('PUT', '/me/player/repeat?state=context&device_id=' + dispositivo);
                                } catch (e) {
                                    arranco = false;
                                    this.estadoMusica = e.message;
                                }
                            },
                        };

                        player.addListener('ready', ({ device_id }) => {
                            dispositivo = device_id;
                            this.reproductor = adaptador;
                            this.estadoMusica = 'Lista. Suena al encender el parlante.';
                            if (this.encendido) this.sonarMusica();
                        });
                        player.addListener('not_ready', () => { this.estadoMusica = 'Spotify perdió la conexión: se reintenta solo.'; });
                        player.addListener('account_error', () => { this.estadoMusica = 'La cuenta de Spotify no es Premium: su reproductor web no suena sin ella.'; });
                        player.addListener('authentication_error', () => { this.estadoMusica = 'Spotify no aceptó el acceso. Vuelve a conectar la cuenta en «Conectar Spotify».'; });
                        player.addListener('initialization_error', () => { this.estadoMusica = 'Este navegador no puede tocar Spotify. Usa Chrome o Edge en un computador.'; });
                        player.addListener('player_state_changed', (s) => {
                            if (s && ! s.paused && this.conMusica) this.estadoMusica = this.hablando ? 'Más baja: está hablando un anuncio.' : 'Sonando.';
                        });
                        player.connect();
                    };

                    if (window.Spotify?.Player) { crear(); return; }

                    window.onSpotifyWebPlaybackSDKReady = crear;
                    if (! document.getElementById('spotify-sdk')) {
                        const s = document.createElement('script');
                        s.id = 'spotify-sdk';
                        s.src = 'https://sdk.scdn.co/spotify-player.js';
                        document.head.appendChild(s);
                    }
                },

                /** El reproductor oficial de YouTube: lo único que se deja controlar. */
                prepararYouTube() {
                    const crear = () => {
                        const m = this.musica;
                        const vars = { controls: 1, rel: 0, playsinline: 1, loop: 1 };

                        if (m.lista) { vars.listType = 'playlist'; vars.list = m.lista; }
                        // Un video suelto se repite: para eso YouTube pide
                        // que el video sea también su propia lista.
                        else { vars.playlist = m.video; }

                        this.reproductor = new YT.Player('musica-del-parlante', {
                            width: 320, height: 180,
                            videoId: m.lista ? (m.video || undefined) : m.video,
                            playerVars: vars,
                            events: {
                                onReady: () => {
                                    this.reproductor.setVolume(m.volumen);
                                    if (m.lista && m.aleatoria) this.reproductor.setShuffle(true);
                                    this.estadoMusica = 'Lista. Suena al encender el parlante.';
                                    if (this.encendido) this.sonarMusica();
                                },
                                onStateChange: (ev) => {
                                    if (ev.data === YT.PlayerState.PLAYING) {
                                        const titulo = this.reproductor.getVideoData?.()?.title;
                                        this.estadoMusica = titulo ? 'Sonando: ' + titulo : 'Sonando.';
                                    }
                                },
                                // Hay canciones que su dueño no deja poner fuera
                                // de YouTube: se salta a la siguiente.
                                onError: () => {
                                    this.estadoMusica = 'Una canción no se deja reproducir aquí: se salta.';
                                    if (this.musica.lista) this.reproductor.nextVideo();
                                },
                            },
                        });
                    };

                    if (window.YT && window.YT.Player) { crear(); return; }

                    const antes = window.onYouTubeIframeAPIReady;
                    window.onYouTubeIframeAPIReady = () => { antes?.(); crear(); };

                    if (! document.getElementById('youtube-iframe-api')) {
                        const s = document.createElement('script');
                        s.id = 'youtube-iframe-api';
                        s.src = 'https://www.youtube.com/iframe_api';
                        document.head.appendChild(s);
                    }
                },

                sonarMusica() {
                    if (! this.reproductor?.playVideo || ! this.encendido || ! this.conMusica) return;
                    this.reproductor.setVolume(this.hablando ? this.volumenBajo() : this.musica.volumen);

                    // La primera vez, en aleatorio, se arranca en una canción
                    // al azar: mezclar no basta, la primera sería siempre la
                    // misma. Después se retoma donde iba.
                    const lista = this.reproductor.getPlaylist?.() || [];
                    if (this.musica.aleatoria && ! this.yaArranco && lista.length > 1) {
                        this.yaArranco = true;
                        this.reproductor.playVideoAt(Math.floor(Math.random() * lista.length));
                        return;
                    }

                    this.yaArranco = true;
                    this.reproductor.playVideo();
                },

                /**
                 * Con el deslizador: es el volumen de la música sola, y se oye
                 * al momento. Si justo habla un anuncio, la música sigue baja
                 * —a su parte del volumen nuevo— y al terminar sube a este.
                 */
                cambiarVolumen(v) {
                    this.musica.volumen = Math.max(0, Math.min(100, Math.round(v)));
                    // Que no se quede creyendo que habla un anuncio si ya no suena ninguno.
                    this.hablando = !! (this.sonando || window.fabosAnuncios?.url);
                    if (! this.reproductor?.setVolume) return;
                    clearInterval(this.deslizando);
                    this.reproductor.setVolume(this.hablando ? this.volumenBajo() : this.musica.volumen);
                },

                callarMusica() {
                    this.reproductor?.pauseVideo?.();
                },

                /*
                 * Mientras habla un anuncio la música no se detiene: baja.
                 * Detenerla y retomarla dependía de que YouTube obedeciera al
                 * volver, y a veces no volvía. Bajar y subir el volumen no se
                 * pierde. Solo una emergencia la apaga, y queda apagada: no
                 * vuelve sola después de una evacuación.
                 */
                volumenBajo() {
                    return Math.max(4, Math.round(this.musica.volumen * 0.2));
                },

                bajarMusica(emergencia = false) {
                    this.hablando = true;
                    if (! this.reproductor?.setVolume) return;

                    if (emergencia) {
                        this.callarMusica();
                        this.conMusica = false;
                        this.estadoMusica = 'Apagada por un anuncio de emergencia. Marca «Con música» para volver a ponerla.';
                        return;
                    }

                    this.deslizar(this.volumenBajo());
                    if (this.conMusica) this.estadoMusica = 'Más baja: está hablando un anuncio.';
                },

                subirMusica() {
                    this.hablando = false;
                    if (! this.reproductor?.setVolume || ! this.conMusica) return;
                    this.deslizar(this.musica.volumen);
                    this.estadoMusica = this.queSuena();
                },

                /** «Sonando: <título>» con YouTube; con Spotify lo dice la lista de abajo. */
                queSuena() {
                    const titulo = this.musica?.fuente !== 'spotify' && this.reproductor?.getVideoData?.()?.title;
                    return titulo ? 'Sonando: ' + titulo : 'Sonando.';
                },

                /**
                 * Del volumen de ahora al pedido, en medio segundo: sin saltos.
                 *
                 * Con la pestaña oculta —el anuncio se lanzó desde otra—, el
                 * navegador frena los temporizadores a uno por segundo, o por
                 * minuto: el deslizado tardaba más que el anuncio y la música
                 * no llegaba a bajar. Oculta, salta de una. Y a la vista, cada
                 * paso se calcula por el reloj y no por cuántos pasos van.
                 */
                deslizar(hasta) {
                    clearInterval(this.deslizando);
                    const desde = this.reproductor.getVolume?.() ?? this.musica.volumen;

                    if (document.hidden || desde === hasta) {
                        this.reproductor.setVolume(hasta);
                        return;
                    }

                    const inicio = Date.now();
                    this.deslizando = setInterval(() => {
                        const f = Math.min(1, (Date.now() - inicio) / 500);
                        this.reproductor.setVolume(Math.round(desde + (hasta - desde) * f));
                        if (f >= 1) clearInterval(this.deslizando);
                    }, 50);
                },

                /**
                 * Otra lista, en vivo. Spotify arranca la nueva en una
                 * canción al azar; YouTube la carga y sigue en aleatorio. Si
                 * el parlante está apagado, queda lista para cuando se encienda.
                 */
                cambiarLista(d) {
                    if (! this.musica || d.fuente !== this.musica.fuente) { location.reload(); return; }

                    this.musica.lista = d.lista;
                    this.musica.video = d.video;
                    this.estadoMusica = 'Lista: ' + d.nombre + '.';
                    const sonar = this.encendido && this.conMusica;

                    if (d.fuente === 'spotify') {
                        this.reproductor?.cambiarLista?.(sonar);
                        return;
                    }

                    const yt = this.reproductor;
                    if (! yt?.loadPlaylist) return;
                    const cargar = sonar ? 'load' : 'cue';
                    if (d.lista) yt[cargar + 'Playlist']({ list: d.lista, listType: 'playlist', index: 0 });
                    else yt[cargar + 'VideoById'](d.video);
                    if (d.lista && this.musica.aleatoria) setTimeout(() => yt.setShuffle?.(true), 800);
                },

                cambiarMusica() {
                    try { localStorage.setItem(CON_MUSICA, this.conMusica ? 'si' : 'no'); } catch (e) {}
                    if (this.conMusica) { this.sonarMusica(); } else { this.callarMusica(); this.estadoMusica = 'Sin música.'; }
                },

                apagar() {
                    this.encendido = false;
                    this.cola = [];
                    // Se suelta el audio y se da por terminado lo que sonaba:
                    // así la repetición pendiente ve que no hay audio y no
                    // vuelve a sonar después de apagar.
                    const audio = this.audio;
                    this.audio = null;
                    audio?.pause();
                    audio?.onended?.();
                    this.sonando = null;
                    this.callarMusica();
                },

                /**
                 * A la cola, no encima: dos anuncios a la misma hora suenan
                 * uno detrás del otro, no a la vez.
                 */
                lanzar(e) {
                    if (! this.audio) this.audio = new Audio();
                    this.cola.push(e);
                    if (! this.sonando) this.siguiente();
                },

                async siguiente() {
                    const e = this.cola.shift();
                    if (! e) {
                        // Terminó lo que había que decir: la música vuelve a su volumen.
                        this.sonando = null;
                        this.subirMusica();
                        return;
                    }

                    // La música baja antes de hablar; con una emergencia, se apaga.
                    this.bajarMusica(e.alerta);
                    this.sonando = e.titulo;
                    // Una alerta, lo más fuerte que dé el audio sin romperse.
                    if (this.audio) window.fabosVolumen?.ajustar(this.audio, e.alerta);

                    // El tono, una vez antes de la primera vez que suena.
                    if (e.previo && this.audio) {
                        await this.reproducir(e.previo);
                        await new Promise((r) => setTimeout(r, 300));
                    }

                    for (let i = 0; i < e.repeticiones; i++) {
                        if (i > 0) await new Promise((r) => setTimeout(r, e.pausa * 1000));
                        if (! this.audio) break;
                        await this.reproducir(e.url);
                    }
                    this.siguiente();
                },

                reproducir(url) {
                    return new Promise((listo) => {
                        this.audio.src = url;
                        this.audio.onended = listo;
                        this.audio.onerror = listo;
                        this.audio.play().catch(listo);
                    });
                },

                cuando(e) {
                    return (e.hoy ? '' : 'mañana ') + e.hora;
                },
            };
        }
    </script>
</x-filament-panels::page>
