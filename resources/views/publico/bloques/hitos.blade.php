{{-- La línea de tiempo: qué pasó y cuándo. --}}
<div class="bloque">
    @if (filled($datos['titulo'] ?? null))
        <h2>{{ $datos['titulo'] }}</h2>
    @endif

    <ol class="hitos">
        @foreach ($datos['hitos'] ?? [] as $hito)
            <li>
                @if (filled($hito['cuando'] ?? null))
                    <span class="cuando">{{ $hito['cuando'] }}</span>
                @endif

                <b>{{ $hito['titulo'] }}</b>

                @if (filled($hito['texto'] ?? null))
                    <p>{{ $hito['texto'] }}</p>
                @endif
            </li>
        @endforeach
    </ol>
</div>
