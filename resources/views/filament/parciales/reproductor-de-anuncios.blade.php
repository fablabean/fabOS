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

        tocar(url) {
            if (this.url === url && this.audio && ! this.audio.paused) {
                this.parar();
                return;
            }

            this.parar();
            this.url = url;
            this.audio = new Audio(url);
            this.audio.addEventListener('ended', () => this.parar());
            this.marcar(url, true);
            // Si el navegador no deja sonar —sin permiso de audio, archivo
            // borrado—, el botón no se queda marcado como si sonara.
            this.audio.play().catch(() => this.parar());
        },

        parar() {
            this.audio?.pause();
            if (this.url) this.marcar(this.url, false);
            this.audio = null;
            this.url = null;
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
