<x-filament-panels::page>
    {{-- La agenda se vuelve a pedir cada cinco minutos: un horario nuevo o un
         audio recién generado entran sin recargar. Va en un JSON aparte y no
         dentro de x-data porque Alpine no relee x-data cuando Livewire
         repinta; el reloj lo lee del JSON en cada vuelta. --}}
    <div wire:poll.300s>
        <script type="application/json" id="agenda-del-parlante">@json($this->eventos())</script>
    </div>

    <div x-data="parlanteDelLaboratorio(@js($this->musica()))" x-init="iniciar()" class="space-y-6">
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
                <x-slot name="description">Suena mientras el parlante está encendido y se pausa durante cada anuncio. Se cambia en «Música», arriba.</x-slot>

                <div class="flex flex-wrap items-start gap-6">
                    <div wire:ignore class="overflow-hidden rounded-lg" style="width:320px;height:180px;background:#000">
                        <div id="musica-del-parlante"></div>
                    </div>
                    <div class="space-y-3 text-sm">
                        <label class="flex items-center gap-2">
                            <input type="checkbox" x-model="conMusica" x-on:change="cambiarMusica()" class="rounded">
                            Con música
                        </label>
                        <p class="text-gray-600 dark:text-gray-300" x-text="estadoMusica"></p>
                        <p class="text-gray-500">Volumen <span x-text="musica.volumen"></span> % · <span x-text="musica.aleatoria ? 'en orden aleatorio' : 'en orden'"></span>.</p>
                    </div>
                </div>
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
                    window.addEventListener('fabos-anuncio', (ev) => {
                        if (ev.detail.sonando) this.bajarMusica(ev.detail.emergencia);
                        else this.subirMusica();
                    });
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
                    this.encendido = true;
                    this.sonarMusica();
                },

                // ---------------------------------------------- la música

                /** El reproductor oficial de YouTube: lo único que se deja controlar. */
                prepararMusica() {
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
                                    if (ev.data === YT.PlayerState.PLAYING) this.estadoMusica = 'Sonando.';
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
                    this.estadoMusica = 'Sonando.';
                },

                /** Del volumen de ahora al pedido, en medio segundo: sin saltos. */
                deslizar(hasta) {
                    clearInterval(this.deslizando);
                    let ahora = this.reproductor.getVolume?.() ?? this.musica.volumen;
                    const paso = (hasta - ahora) / 10;

                    this.deslizando = setInterval(() => {
                        ahora += paso;
                        const listo = paso === 0 || (paso < 0 ? ahora <= hasta : ahora >= hasta);
                        this.reproductor.setVolume(Math.round(listo ? hasta : ahora));
                        if (listo) clearInterval(this.deslizando);
                    }, 50);
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
                    this.bajarMusica(/\/emergencia\.mp3(\?|$)/.test(e.previo || ''));
                    this.sonando = e.titulo;

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
