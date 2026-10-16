{{-- El reproductor de los anuncios con voz, uno para todo el panel (§3).

     Uno solo a propósito: los anuncios se lanzan por los parlantes del
     laboratorio, y dos sonando a la vez no los entiende nadie. Lanzar otro
     corta el que sonaba; pulsar el mismo lo detiene. Lo usan el botón de cada
     fila de la lista y los favoritos de la barra de arriba, que se marcan
     juntos mientras suena su audio. --}}
<script>
    window.fabosAnuncios ??= {
        audio: null,
        url: null,

        // Con el sonido previo —el tono de aeropuerto—, si el anuncio lo
        // lleva: suena primero, y al terminar entra la voz.
        tocar(url, previo = null) {
            if (this.url === url && this.audio) {
                this.parar();
                return;
            }

            this.parar();
            this.url = url;
            this.marcar(url, true);
            // Quien tenga música sonando —el Parlante— se calla mientras tanto.
            window.dispatchEvent(new CustomEvent('fabos-anuncio', { detail: { sonando: true } }));

            const voz = () => {
                if (this.url !== url) return;   // lo pararon mientras sonaba el tono
                this.audio = new Audio(url);
                this.audio.addEventListener('ended', () => this.parar());
                // Si el navegador no deja sonar —sin permiso de audio, archivo
                // borrado—, el botón no se queda marcado como si sonara.
                this.audio.play().catch(() => this.parar());
            };

            if (! previo) {
                voz();
                return;
            }

            const tono = new Audio(previo);
            this.audio = tono;
            let siguio = false;
            // Una sola vez: si el tono falla y además termina, la voz no entra dos veces.
            const despues = () => { if (siguio || this.audio !== tono) return; siguio = true; voz(); };
            tono.addEventListener('ended', despues);
            tono.addEventListener('error', despues);
            tono.play().catch(despues);
        },

        parar() {
            this.audio?.pause();
            const sonaba = this.url !== null;
            if (this.url) this.marcar(this.url, false);
            this.audio = null;
            this.url = null;
            // Y vuelve la música, si la había.
            if (sonaba) window.dispatchEvent(new CustomEvent('fabos-anuncio', { detail: { sonando: false } }));
        },

        marcar(url, sonando) {
            document.querySelectorAll('[data-anuncio-audio]').forEach((b) => {
                if (b.dataset.anuncioAudio === url) b.classList.toggle('anuncio-sonando', sonando);
            });
        },
    };
</script>
<style>
    @keyframes anuncio-sonando { 50% { opacity: .45; } }
    .anuncio-sonando { color: rgb(var(--success-600, 22 163 74)) !important; animation: anuncio-sonando 1s ease-in-out infinite; }
    .anuncio-sonando svg { color: inherit !important; }
</style>
