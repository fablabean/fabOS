{{-- La salida de la página: a dónde se va después de leerla.

     Si hay varios marcados como destacados, ninguno lo está: dos botones de
     color compitiendo no destacan nada. --}}
@php
    $botones = $datos['botones'] ?? [];
    $destacados = collect($botones)->where('destacado', true)->count();
@endphp

<div class="bloque">
    <div class="botones">
        @foreach ($botones as $boton)
            <a class="btn {{ ($boton['destacado'] ?? false) && $destacados === 1 ? '' : 'secundario' }}"
               href="{{ $boton['url'] }}">{{ $boton['texto'] }}</a>
        @endforeach
    </div>
</div>
