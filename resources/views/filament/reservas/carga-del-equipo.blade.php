<x-filament-widgets::widget>

    {{-- Estilos propios, como en el embudo de proyectos: Filament no trae las
         utilidades de rejilla que necesita una tarjeta a medida. --}}
    <style>
        .carga .rejilla { display:grid; gap:.7rem;
                          grid-template-columns:repeat(auto-fit,minmax(11rem,1fr)); }
        .carga a.quien { display:block; padding:.8rem .9rem; border-radius:.6rem;
                        border:1px solid rgb(229 231 235); background:rgb(255 255 255);
                        text-decoration:none; color:inherit; transition:border-color .12s; }
        .carga a.quien:hover { border-color:rgb(245 158 11); }
        .carga .nombre { font-size:.82rem; font-weight:600; line-height:1.25; }
        .carga .apellidos { font-size:.82rem; color:rgb(107 114 128); line-height:1.25;
                            margin-bottom:.55rem; }
        /* Sin apellidos separados la tarjeta perdia su hueco y las cifras de
           una fila quedaban a distinta altura que las de al lado. */
        .carga .nombre.solo { margin-bottom:.55rem; }
        .carga .cifras { display:grid; grid-template-columns:repeat(3,1fr); gap:.3rem; }
        .carga .cifra { text-align:center; }
        .carga .cuantos { font-size:1.35rem; font-weight:600; letter-spacing:-.02em;
                          line-height:1.15; }
        .carga .que { font-size:.66rem; color:rgb(107 114 128); text-transform:uppercase;
                      letter-spacing:.05em; }
        .carga .cero .cuantos { color:rgb(156 163 175); }
        .carga .ahora .cuantos { color:rgb(5 150 105); }
        .carga .nota { font-size:.78rem; color:rgb(107 114 128); margin-top:.7rem; }
        .dark .carga a.quien { border-color:rgb(55 65 81); background:rgb(31 41 55); }
    </style>

    @php
        $tarjetas = $this->getTarjetas();
        $enCurso = collect($tarjetas)->sum('activas');
    @endphp

    @if ($tarjetas)
        <div class="carga">
            <x-filament::section collapsible persist-collapsed id="carga-del-equipo">
                <x-slot name="heading">Quién tiene qué</x-slot>
                <x-slot name="description">
                    @if ($enCurso)
                        {{ $enCurso }} {{ $enCurso === 1 ? 'reserva corriendo' : 'reservas corriendo' }} ahora mismo.
                    @else
                        Nada corriendo ahora mismo.
                    @endif
                </x-slot>

                <div class="rejilla">
                    @foreach ($tarjetas as $t)
                        <a class="quien" href="{{ $t['enlace'] }}">
                            <div class="nombre {{ $t['apellidos'] ? '' : 'solo' }}">{{ $t['nombre'] }}</div>
                            @if ($t['apellidos'])
                                <div class="apellidos">{{ $t['apellidos'] }}</div>
                            @endif

                            <div class="cifras">
                                <div class="cifra ahora {{ $t['activas'] ? '' : 'cero' }}">
                                    <div class="cuantos">{{ $t['activas'] }}</div>
                                    <div class="que">activas</div>
                                </div>
                                <div class="cifra {{ $t['futuras'] ? '' : 'cero' }}">
                                    <div class="cuantos">{{ $t['futuras'] }}</div>
                                    <div class="que">futuras</div>
                                </div>
                                <div class="cifra {{ $t['cerradas'] ? '' : 'cero' }}">
                                    <div class="cuantos">{{ $t['cerradas'] }}</div>
                                    <div class="que">cerradas</div>
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>

                <p class="nota">
                    Cuenta lo que cada quien tiene a su cargo por cualquiera de las tres vías: su
                    tiempo reservado como asesoría, figurar como quien acompaña o recibe, o estar
                    entre los acompañantes de un espacio. <strong>Activas</strong> es lo que está
                    corriendo ahora mismo; <strong>futuras</strong>, lo que tiene por delante,
                    decidido o esperando decisión; <strong>cerradas</strong>, lo que ya terminó.
                    Lo cancelado y lo rechazado no suma: no ocurrió, y contarlo sería apuntarle a
                    alguien trabajo que no hizo. Cada tarjeta abre el listado ya filtrado por
                    esa persona.
                </p>
            </x-filament::section>
        </div>
    @endif

</x-filament-widgets::widget>
