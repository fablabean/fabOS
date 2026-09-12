<x-filament-widgets::widget>

    {{-- Estilos propios, como el embudo de proyectos y la carga del equipo:
         Filament no trae las utilidades de rejilla de una tarjeta a medida. --}}
    <style>
        .areas-cat .rejilla { display:grid; gap:.7rem;
                              grid-template-columns:repeat(auto-fill,minmax(9.5rem,1fr)); }
        .areas-cat a.zona { display:block; border-radius:.6rem; overflow:hidden;
                            border:1px solid rgb(229 231 235); background:rgb(255 255 255);
                            text-decoration:none; color:inherit; transition:border-color .12s; }
        .areas-cat a.zona:hover { border-color:rgb(245 158 11); }
        /* Baja y ancha: son nueve o diez tarjetas y no tienen que empujar la
           tabla fuera de la pantalla, que es lo que se vino a mirar. */
        .areas-cat .foto { aspect-ratio:16/9; width:100%; object-fit:cover; display:block;
                           background:rgb(243 244 246); }
        .areas-cat .sinfoto { aspect-ratio:16/9; background:rgb(243 244 246); }
        .areas-cat .txt { padding:.55rem .7rem; }
        .areas-cat .nombre { font-size:.82rem; font-weight:600; line-height:1.25; }
        .areas-cat .cuantos { font-size:.72rem; color:rgb(107 114 128); }
        .areas-cat .todos { display:flex; align-items:center; justify-content:center;
                            min-height:100%; font-size:.82rem; font-weight:600; }
        .dark .areas-cat a.zona { border-color:rgb(55 65 81); background:rgb(31 41 55); }
        .dark .areas-cat .foto, .dark .areas-cat .sinfoto { background:rgb(55 65 81); }
    </style>

    @php
        $areas = $this->getAreas();
    @endphp

    @if ($areas)
        <div class="areas-cat">
            <x-filament::section collapsible persist-collapsed id="areas-del-catalogo">
                <x-slot name="heading">Por área</x-slot>
                <x-slot name="description">
                    Cada tarjeta filtra el catálogo. La tabla abre agrupada y plegada.
                </x-slot>

                <div class="rejilla">
                    @foreach ($areas as $a)
                        <a class="zona" href="{{ $a['enlace'] }}">
                            @if ($a['foto'])
                                <img class="foto" src="{{ $a['foto'] }}" alt="{{ $a['nombre'] }}" loading="lazy">
                            @else
                                {{-- Sin foto, el hueco igual: si no, las tarjetas de
                                     una misma fila quedan a distinta altura. --}}
                                <div class="sinfoto"></div>
                            @endif
                            <div class="txt">
                                <div class="nombre">{{ $a['nombre'] }}</div>
                                <div class="cuantos">
                                    {{ $a['cuantos'] }} {{ $a['cuantos'] === 1 ? 'equipo' : 'equipos' }}
                                </div>
                            </div>
                        </a>
                    @endforeach

                    {{-- La salida: quitar un filtro que uno no puso a mano no es
                         evidente, y sin esto hay que ir a buscarlo al embudo. --}}
                    <a class="zona" href="{{ $this->getEnlaceATodos() }}">
                        <div class="txt todos">Ver todo el catálogo</div>
                    </a>
                </div>
            </x-filament::section>
        </div>
    @endif

</x-filament-widgets::widget>
