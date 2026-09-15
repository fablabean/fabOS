{{-- Un párrafo o varios, escritos en el editor.

     El cuerpo se limpia antes de pintarlo: lo que se escribe en el panel lo
     lee cualquiera desde internet, y se pega desde Word más veces de las que
     se escribe a mano. --}}
<div class="bloque">
    @if (filled($datos['titulo'] ?? null))
        <h2>{{ $datos['titulo'] }}</h2>
    @endif

    <div class="prosa">{!! \App\Support\TextoRico::limpiar($datos['cuerpo'] ?? null) !!}</div>
</div>
