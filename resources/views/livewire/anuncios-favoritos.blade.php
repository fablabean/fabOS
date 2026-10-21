{{-- Los anuncios favoritos en la barra de arriba. Sin favoritos no ocupa
     sitio. En pantallas angostas queda solo el icono, con el título en la
     ayuda emergente. --}}
<div class="anuncios-favoritos">
    @foreach ($anuncios as $anuncio)
        <button type="button"
                class="anuncio-favorito"
                data-anuncio-audio="{{ $anuncio->audioUrl() }}"
                x-on:click="window.fabosAnuncios.tocar(@js($anuncio->audioUrl()), @js($anuncio->sonidoPrevioUrl()), @js($anuncio->frecuencia() + ['alerta' => $anuncio->esAlerta()]))"
                title="Lanzar: {{ $anuncio->titulo }}"
                aria-label="Lanzar el anuncio {{ $anuncio->titulo }}">
            <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6.3 2.84A1.5 1.5 0 0 0 4 4.11v11.78a1.5 1.5 0 0 0 2.3 1.27l9.34-5.89a1.5 1.5 0 0 0 0-2.54L6.3 2.84Z"/></svg>
            <span>{{ $anuncio->titulo }}</span>
        </button>
    @endforeach

    <style>
        .anuncios-favoritos { display: flex; align-items: center; gap: .4rem; margin-inline-end: .75rem; }
        .anuncio-favorito {
            display: inline-flex; align-items: center; gap: .35rem;
            padding: .3rem .65rem; border-radius: 9999px; font-size: .8rem; font-weight: 500;
            border: 1px solid rgb(0 0 0 / .12); background: rgb(0 0 0 / .02); color: inherit;
            max-width: 11rem; white-space: nowrap; cursor: pointer;
        }
        .anuncio-favorito:hover { border-color: rgb(var(--primary-500, 245 158 11)); }
        .anuncio-favorito svg { width: .8rem; height: .8rem; flex: none; color: rgb(var(--primary-600, 217 119 6)); }
        .anuncio-favorito span { overflow: hidden; text-overflow: ellipsis; }
        .dark .anuncio-favorito { border-color: rgb(255 255 255 / .15); background: rgb(255 255 255 / .04); }
        @media (max-width: 64rem) {
            .anuncio-favorito { padding: .35rem; }
            .anuncio-favorito span { display: none; }
        }
    </style>
</div>
