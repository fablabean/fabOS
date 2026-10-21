{{-- El reproductor de los anuncios con voz, uno para todo el panel (§3).

     Uno solo a propósito: los anuncios se lanzan por los parlantes del
     laboratorio, y dos sonando a la vez no los entiende nadie. Lanzar otro
     corta el que sonaba; pulsar el mismo lo detiene. Lo usan el botón de cada
     fila de la lista y los favoritos de la barra de arriba, que se marcan
     juntos mientras suena su audio. --}}
<script>
    /*
     * El volumen de las alertas: lo más fuerte que dé el audio sin romperse.
     *
     * El elemento <audio> no pasa de su volumen 1. Para ir más allá el sonido
     * se pasa por el motor de audio del navegador: se sube unos 7 dB y entra
     * a un limitador que aplasta los picos antes de que saturen. Sube lo que
     * suena flojo —la voz— sin que lo fuerte —el timbre— se distorsione.
     *
     * Solo se engancha si el motor ya está andando: un elemento enganchado a
     * un motor dormido no suena, y una alerta muda es peor que una floja. Por
     * eso se despierta en cada clic —encender el parlante, lanzar un anuncio—,
     * que es lo único con lo que el navegador deja arrancarlo.
     */
    window.fabosVolumen ??= {
        ctx: null,
        cadenas: new WeakMap(),

        despertar() {
            try {
                const AC = window.AudioContext || window.webkitAudioContext;
                if (! AC) return;
                this.ctx ??= new AC();
                if (this.ctx.state !== 'running') this.ctx.resume().catch(() => {});
            } catch (e) {}
        },

        ajustar(audio, alerta) {
            audio.volume = 1;
            let c = this.cadenas.get(audio);

            if (! c) {
                if (! alerta || this.ctx?.state !== 'running') return;
                try {
                    const fuente = this.ctx.createMediaElementSource(audio);
                    c = { ganancia: this.ctx.createGain(), limitador: this.ctx.createDynamicsCompressor() };
                    fuente.connect(c.ganancia).connect(c.limitador).connect(this.ctx.destination);
                    this.cadenas.set(audio, c);
                } catch (e) { return; }
            }

            const l = c.limitador;
            if (alerta) {
                c.ganancia.gain.value = 2.2;
                l.threshold.value = -10; l.knee.value = 4; l.ratio.value = 12;
                l.attack.value = 0.003; l.release.value = 0.25;
            } else {
                // El mismo elemento suena también lo que no es alerta: tal cual.
                c.ganancia.gain.value = 1;
                l.threshold.value = 0; l.knee.value = 0; l.ratio.value = 1;
            }
        },
    };

    window.fabosAnuncios ??= {
        el: null,
        url: null,
        turno: 0,

        // Con el sonido previo —el tono de aeropuerto—, si el anuncio lo
        // lleva: suena primero, y al terminar entra la voz. Un favorito trae
        // además la frecuencia de su horario: tantas veces, con tal pausa.
        tocar(url, previo = null, { repeticiones = 1, pausa = 0, alerta = false } = {}) {
            if (this.url === url) {
                this.parar();
                return;
            }

            this.parar();
            const turno = this.turno;
            const sigue = () => this.turno === turno;
            alerta ||= /\/emergencia\.mp3(\?|$)/.test((previo || '') + ' ' + url);

            window.fabosVolumen.despertar();
            // Uno solo, reusado: así se engancha una vez al limitador.
            this.el ??= new Audio();
            window.fabosVolumen.ajustar(this.el, alerta);

            this.url = url;
            this.marcar(url, true);
            // Quien tenga música sonando —el Parlante— se calla mientras tanto.
            // Y si es una emergencia —por su timbre—, la música se apaga del todo.
            this.avisar({ sonando: true, emergencia: alerta });

            const esperar = (ms) => new Promise((r) => setTimeout(r, ms));
            const sonar = (src) => new Promise((listo) => {
                if (! sigue()) return listo(false);
                this.el.src = src;
                this.el.onended = () => listo(true);
                this.el.onerror = () => listo(false);
                this.el.play().catch(() => listo(false));
            });

            (async () => {
                // Si el tono falla, la voz entra igual.
                if (previo && await sonar(previo)) await esperar(300);

                for (let i = 0; i < Math.max(1, repeticiones); i++) {
                    if (i > 0) await esperar(pausa * 1000);
                    // Si el navegador no deja sonar —sin permiso de audio,
                    // archivo borrado—, el botón no se queda marcado.
                    if (! sigue() || ! await sonar(url)) break;
                }

                if (sigue()) this.parar();
            })();
        },

        parar() {
            this.turno++;
            this.el?.pause();
            const sonaba = this.url !== null;
            if (this.url) this.marcar(this.url, false);
            this.url = null;
            // Y vuelve la música, si la había.
            if (sonaba) this.avisar({ sonando: false });
        },

        /*
         * A la música del Parlante, esté en esta pestaña o en otra del mismo
         * navegador: el anuncio se lanza a menudo desde la lista o desde otra
         * ventana, y la música tiene que bajar igual. Otro computador no se
         * entera: suena por sus propios parlantes.
         */
        pestana: Math.random().toString(36).slice(2),
        canal: 'BroadcastChannel' in window ? new BroadcastChannel('fabos-anuncio') : null,

        avisar(detalle) {
            window.dispatchEvent(new CustomEvent('fabos-anuncio', { detail: detalle }));
            try { this.canal?.postMessage({ ...detalle, pestana: this.pestana }); } catch (e) {}
        },

        marcar(url, sonando) {
            document.querySelectorAll('[data-anuncio-audio]').forEach((b) => {
                if (b.dataset.anuncioAudio === url) b.classList.toggle('anuncio-sonando', sonando);
            });
        },
    };

    // Si se cierra la pestaña a mitad de un anuncio, la música no se queda baja.
    window.addEventListener('pagehide', () => window.fabosAnuncios.parar());
</script>
<style>
    @keyframes anuncio-sonando { 50% { opacity: .45; } }
    .anuncio-sonando { color: rgb(var(--success-600, 22 163 74)) !important; animation: anuncio-sonando 1s ease-in-out infinite; }
    .anuncio-sonando svg { color: inherit !important; }
</style>
