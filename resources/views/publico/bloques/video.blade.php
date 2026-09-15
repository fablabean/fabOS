{{-- Un video del laboratorio.

     Con controles y sin autoarranque: esto es contenido que alguien decide
     ver, no el fondo del banner. --}}
<figure class="bloque medio">
    <video controls preload="metadata"
           @if (filled($datos['poster'] ?? null)) poster="{{ asset('storage/' . $datos['poster']) }}" @endif>
        <source src="{{ asset('storage/' . $datos['video']) }}">
    </video>

    @if (filled($datos['pie'] ?? null))
        <figcaption>{{ $datos['pie'] }}</figcaption>
    @endif
</figure>
