{{--
    El paginador del sitio público.

    El de Laravel viene pensado para Tailwind, que el sitio no carga: salía en
    inglés, con «« Previous» y una flecha del tamaño de la pantalla. Este usa
    los colores del sitio y dice las cosas en español.
--}}
@if ($paginator->hasPages())
    <nav class="paginacion" role="navigation" aria-label="Páginas">
        @if ($paginator->onFirstPage())
            <span class="pag-flecha apagada" aria-disabled="true">← Anteriores</span>
        @else
            <a class="pag-flecha" href="{{ $paginator->previousPageUrl() }}" rel="prev">← Anteriores</a>
        @endif

        <span class="pag-numeros">
            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="pag-puntos">…</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="pag-num actual" aria-current="page">{{ $page }}</span>
                        @else
                            <a class="pag-num" href="{{ $url }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach
        </span>

        @if ($paginator->hasMorePages())
            <a class="pag-flecha" href="{{ $paginator->nextPageUrl() }}" rel="next">Siguientes →</a>
        @else
            <span class="pag-flecha apagada" aria-disabled="true">Siguientes →</span>
        @endif
    </nav>

    <p class="pag-cuenta">
        {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} de {{ $paginator->total() }}
    </p>

    @once
        <style>
            .paginacion { display:flex; flex-wrap:wrap; gap:.6rem 1rem; align-items:center;
                          justify-content:space-between; margin:2rem 0 .4rem; font-size:.9rem; }
            .pag-numeros { display:flex; flex-wrap:wrap; gap:.3rem; }
            .pag-num { min-width:2.1rem; text-align:center; padding:.35rem .5rem; border-radius:.4rem;
                       border:1px solid var(--rule); text-decoration:none; color:var(--ink); }
            .pag-num:hover { border-color:var(--accent); color:var(--accent); }
            .pag-num.actual { background:var(--accent); border-color:var(--accent); color:var(--surface); font-weight:700; }
            .pag-flecha { text-decoration:none; color:var(--accent); font-weight:600; }
            .pag-flecha.apagada { color:var(--muted); font-weight:400; }
            .pag-puntos { padding:.35rem .2rem; color:var(--muted); }
            .pag-cuenta { font-size:.8rem; color:var(--muted); margin:0; }
        </style>
    @endonce
@endif
