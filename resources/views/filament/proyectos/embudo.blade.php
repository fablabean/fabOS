<x-filament-widgets::widget>

    {{-- Estilos propios: Filament no trae las utilidades de rejilla que usaría
         una tarjeta a medida, y sin esto las etapas se apilan en columna. --}}
    <style>
        .emb .rejilla { display:grid; gap:.7rem;
                        grid-template-columns:repeat(auto-fit,minmax(8.5rem,1fr)); }
        .emb a.paso { display:block; padding:.8rem .9rem; border-radius:.6rem;
                      border:1px solid rgb(229 231 235); background:rgb(255 255 255);
                      text-decoration:none; color:inherit; transition:border-color .12s; }
        .emb a.paso:hover { border-color:rgb(245 158 11); }
        .emb .etapa { font-size:.75rem; color:rgb(107 114 128); text-transform:uppercase;
                      letter-spacing:.06em; }
        .emb .cuantos { font-size:1.8rem; font-weight:600; letter-spacing:-.02em;
                        line-height:1.15; }
        .emb .valor { font-size:.72rem; color:rgb(107 114 128); }
        .emb .vacia .cuantos { color:rgb(156 163 175); }
        /* Lo pausado no es una etapa del embudo: se separa a la vista para que
           no se lea como una cosa mas que esta avanzando. */
        .emb .parada { border-style:dashed; }
        .emb .parada .cuantos { color:rgb(217 119 6); }
        .emb .nota { font-size:.78rem; color:rgb(107 114 128); margin-top:.7rem; }
        .dark .emb a.paso { border-color:rgb(55 65 81); background:rgb(31 41 55); }
    </style>

    @php
        $tarjetas = $this->getTarjetas();

        $simbolo = config('fabos.money.symbol');
        $pesos = fn (int $v) => $simbolo . number_format($v, 0, ',', '.');

        $enCurso = collect($tarjetas)
            ->reject(fn ($t) => $t['cerrada'] || ($t['pausa'] ?? false))
            ->sum('cuantos');
    @endphp

    <div class="emb">
        <x-filament::section>
            <x-slot name="heading">El embudo</x-slot>
            <x-slot name="description">
                {{ $enCurso }} {{ $enCurso === 1 ? 'proyecto activo' : 'proyectos activos' }},
                por etapa. Cada tarjeta abre el listado ya filtrado.
            </x-slot>

            <div class="rejilla">
                @foreach ($tarjetas as $t)
                    <a class="paso {{ $t['cuantos'] === 0 ? 'vacia' : '' }} {{ ($t['pausa'] ?? false) ? 'parada' : '' }}"
                       href="{{ $this->enlaceDe($t) }}">
                        <div class="etapa">{{ $t['nombre'] }}</div>
                        <div class="cuantos">{{ $t['cuantos'] }}</div>
                        <div class="valor">
                            @if ($t['valor'] > 0)
                                {{ $pesos($t['valor']) }}
                            @elseif ($t['cerrada'])
                                cerrados en {{ $this->ano() }}
                            @elseif ($t['pausa'] ?? false)
                                parados, no muertos
                            @else
                                &nbsp;
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>

            <p class="nota">
                Las cinco primeras cuentan lo activo, que es trabajo por delante. Lo pausado va
                aparte: sigue vivo, pero no está avanzando, y sumarlo diría que hay más cosas en
                marcha de las que hay. La de cierre
                cuenta lo cerrado en {{ $this->ano() }}: el total histórico crece para siempre y
                a los dos años deja de decir nada. El valor es lo acordado, o lo estimado
                mientras no haya acuerdo.
            </p>
        </x-filament::section>
    </div>

</x-filament-widgets::widget>
