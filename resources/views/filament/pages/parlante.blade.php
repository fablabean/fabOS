<x-filament-panels::page>
    {{-- La agenda se vuelve a pedir cada cinco minutos: un horario nuevo o un
         audio recién generado entran sin recargar. Va en un JSON aparte y no
         dentro de x-data porque Alpine no relee x-data cuando Livewire
         repinta; el reloj lo lee del JSON en cada vuelta. --}}
    <div wire:poll.300s>
        <script type="application/json" id="agenda-del-parlante">@json($this->eventos())</script>
    </div>

    <div x-data="parlanteDelLaboratorio()" x-init="iniciar()" class="space-y-6">
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
        function parlanteDelLaboratorio() {
            const GRACIA = 90 * 1000;
            const MEMORIA = 'fabos-parlante-hechos';

            return {
                encendido: false,
                sonando: null,
                proximos: [],
                hechos: {},
                cola: [],
                audio: null,

                iniciar() {
                    try { this.hechos = JSON.parse(localStorage.getItem(MEMORIA) || '{}'); } catch (e) { this.hechos = {}; }
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
                    if (! e) { this.sonando = null; return; }

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
