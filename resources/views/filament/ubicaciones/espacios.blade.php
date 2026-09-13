<x-filament-widgets::widget>

    {{-- Estilos propios, como el embudo de proyectos y las areas del catalogo:
         Filament no trae las utilidades de rejilla de una tarjeta a medida. --}}
    <style>
        .ubic-esp .rejilla { display:grid; gap:.7rem;
                             grid-template-columns:repeat(auto-fill,minmax(10rem,1fr)); }
        .ubic-esp a.sala { display:block; padding:.8rem .9rem; border-radius:.6rem;
                           border:1px solid rgb(229 231 235); background:rgb(255 255 255);
                           text-decoration:none; color:inherit; transition:border-color .12s; }
        .ubic-esp a.sala:hover { border-color:rgb(245 158 11); }
        .ubic-esp .nombre { font-size:.82rem; font-weight:600; line-height:1.25; }
        .ubic-esp .cuantas { font-size:1.35rem; font-weight:600; letter-spacing:-.02em;
                             line-height:1.2; margin-top:.3rem; }
        .ubic-esp .que { font-size:.7rem; color:rgb(107 114 128); }
        .ubic-esp .todas { display:flex; align-items:center; justify-content:center;
                           min-height:100%; font-size:.82rem; font-weight:600; }
        .ubic-esp .huerfanas { font-size:.78rem; color:rgb(180 83 9); margin-top:.7rem; }
        .dark .ubic-esp a.sala { border-color:rgb(55 65 81); background:rgb(31 41 55); }
    </style>

    @php
        $espacios = $this->getEspacios();
        $huerfanas = $this->getHuerfanas();
    @endphp

    @if ($espacios || $huerfanas)
        <div class="ubic-esp">
            <x-filament::section collapsible persist-collapsed id="espacios-con-ubicaciones">
                <x-slot name="heading">Por espacio</x-slot>
                <x-slot name="description">
                    Cada tarjeta filtra la lista. Cuenta también lo que cuelga: un estante con
                    dieciséis gavetas son diecisiete muebles.
                </x-slot>

                <div class="rejilla">
                    @foreach ($espacios as $e)
                        <a class="sala" href="{{ $e['enlace'] }}">
                            <div class="nombre">{{ $e['nombre'] }}</div>
                            <div class="cuantas">{{ $e['cuantas'] }}</div>
                            <div class="que">{{ $e['cuantas'] === 1 ? 'mueble' : 'muebles' }}</div>
                        </a>
                    @endforeach

                    {{-- La salida: quitar un filtro que uno no puso a mano no es
                         evidente, y sin esto la pantalla se siente atascada. --}}
                    <a class="sala" href="{{ $this->getEnlaceATodas() }}">
                        <div class="todas">Ver todas</div>
                    </a>
                </div>

                @if ($huerfanas)
                    {{-- Un mueble sin sala no se encuentra yendo a buscarlo: se
                         dice, porque es algo que hay que arreglar y no un
                         estado normal. --}}
                    <p class="huerfanas">
                        {{ $huerfanas }} {{ $huerfanas === 1 ? 'mueble no está' : 'muebles no están' }}
                        en ninguna sala. Salen abajo, en «Sin espacio asignado»: se arregla poniéndole
                        el espacio a la ubicación raíz de la que cuelgan.
                    </p>
                @endif
            </x-filament::section>
        </div>
    @endif

</x-filament-widgets::widget>
