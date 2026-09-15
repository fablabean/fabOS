{{-- La ficha: área, responsable, cuándo. Una lista de definición y no una
     tabla: son pares, no filas y columnas, y en un teléfono una tabla de dos
     columnas parte las palabras. --}}
<div class="bloque">
    @if (filled($datos['titulo'] ?? null))
        <h2>{{ $datos['titulo'] }}</h2>
    @endif

    <dl class="ficha">
        @foreach ($datos['filas'] ?? [] as $fila)
            <div>
                <dt>{{ $fila['clave'] }}</dt>
                <dd>{{ $fila['valor'] }}</dd>
            </div>
        @endforeach
    </dl>
</div>
