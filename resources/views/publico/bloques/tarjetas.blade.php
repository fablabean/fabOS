{{-- Tarjetas con imagen y botón: cada una lleva a un sitio —una facultad, un
     formulario, una convocatoria—. La imagen también lleva, porque es donde
     la gente pulsa primero. --}}
@php
    $tarjetas = collect($datos['tarjetas'] ?? [])->filter(fn ($t) => filled($t['titulo'] ?? null) && filled($t['url'] ?? null));
    $columnas = in_array((int) ($datos['columnas'] ?? 3), [2, 3, 4], true) ? (int) $datos['columnas'] : 3;
@endphp

<div class="bloque">
    @if (filled($datos['titulo'] ?? null))
        <h2>{{ $datos['titulo'] }}</h2>
    @endif

    <div class="tarjetas" style="--columnas: {{ $columnas }}">
        @foreach ($tarjetas as $t)
            <article class="tarjeta">
                @if (filled($t['imagen'] ?? null))
                    <a class="tarjeta-imagen" href="{{ $t['url'] }}" tabindex="-1" aria-label="{{ $t['titulo'] }}">
                        <img src="{{ asset('storage/' . $t['imagen']) }}" alt="" loading="lazy">
                    </a>
                @endif
                <div class="tarjeta-cuerpo">
                    <h3>{{ $t['titulo'] }}</h3>
                    @if (filled($t['texto'] ?? null))
                        <p>{{ $t['texto'] }}</p>
                    @endif
                    <a class="btn" href="{{ $t['url'] }}">{{ filled($t['boton'] ?? null) ? $t['boton'] : 'Ver más' }}</a>
                </div>
            </article>
        @endforeach
    </div>
</div>
