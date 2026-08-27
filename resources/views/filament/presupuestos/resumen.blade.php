<x-filament-widgets::widget>

    {{-- Estilos propios: Filament no trae las utilidades de rejilla que usaría
         una tarjeta a medida, y sin esto las cifras se apilan. --}}
    <style>
        .res .rejilla { display:grid; gap:1rem;
                        grid-template-columns:repeat(auto-fit,minmax(9.5rem,1fr)); }
        .res dt { font-size:.78rem; color:rgb(107 114 128); }
        .res dd { margin:0; font-size:1.45rem; font-weight:600; letter-spacing:-.02em;
                  line-height:1.25; }
        .res dd .pie { display:block; font-size:.72rem; font-weight:400;
                       color:rgb(107 114 128); letter-spacing:0; }
        .res .barra { height:.45rem; border-radius:99px; background:rgb(229 231 235);
                      overflow:hidden; margin-top:1rem; }
        .res .barra span { display:block; height:100%; background:rgb(245 158 11); }
        .res .nota { font-size:.78rem; color:rgb(107 114 128); margin-top:.6rem; }
        .res .venta { display:flex; flex-wrap:wrap; gap:1.5rem; align-items:baseline; }
        .res .venta b { font-size:1.1rem; font-weight:600; }
        .dark .res .barra { background:rgb(55 65 81); }
    </style>

    @php
        $resumen = $this->getResumen();
        $gasto = $resumen['gasto'];
        $venta = $resumen['venta'];

        $simbolo = config('fabos.money.symbol');
        $pesos = fn (int $v) => $simbolo . number_format($v, 0, ',', '.');
    @endphp

    <div class="res">
        <x-filament::section>
            <x-slot name="heading">El año {{ $this->ano() }}</x-slot>
            <x-slot name="description">
                Lo que la Universidad asignó para gastar, en
                {{ $gasto['cuantos'] }}
                {{ $gasto['cuantos'] === 1 ? 'presupuesto' : 'presupuestos' }},
                y en qué va.
            </x-slot>

            <dl class="rejilla">
                <div>
                    <dt>Aprobado</dt>
                    <dd>
                        {{ $pesos($gasto['aprobado']) }}
                        <span class="pie">lo que asignó la Universidad</span>
                    </dd>
                </div>

                <div>
                    <dt>Comprometido</dt>
                    <dd>
                        {{ $pesos($gasto['comprometido']) }}
                        <span class="pie">aprobado, sin llegar</span>
                    </dd>
                </div>

                <div>
                    <dt>Ejecutado</dt>
                    <dd>
                        {{ $pesos($gasto['ejecutado']) }}
                        <span class="pie">
                            @if ($gasto['arranque'] > 0)
                                incluye {{ $pesos($gasto['arranque']) }} de arranque
                            @else
                                recibido
                            @endif
                        </span>
                    </dd>
                </div>

                <div>
                    <dt>Disponible</dt>
                    <dd style="color:rgb(5 150 105)">
                        {{ $pesos($gasto['disponible']) }}
                        <span class="pie">{{ number_format($gasto['usado'], 1, ',', '.') }}% usado</span>
                    </dd>
                </div>
            </dl>

            <div class="barra" role="presentation">
                <span style="width:{{ min(100, max(0, $gasto['usado'])) }}%"></span>
            </div>

            <p class="nota">
                Comprometido y ejecutado no se escriben: salen de las solicitudes de compra.
                Solo cuentan los presupuestos vigentes; un borrador todavía no es plata que se
                pueda comprometer.
            </p>
        </x-filament::section>

        @if ($venta['cuantos'] > 0)
            {{-- Aparte, y a proposito. Una meta de ingresos no es plata asignada:
                 sumarla al aprobado diria que hay mas para gastar de lo que hay. --}}
            <x-filament::section class="mt-4" collapsible collapsed>
                <x-slot name="heading">Aparte · lo que esperamos vender</x-slot>
                <x-slot name="description">
                    Control interno. No entra en el resumen de arriba: es una meta de ingresos,
                    no plata que la Universidad haya asignado.
                </x-slot>

                <div class="venta">
                    <span>Meta del año <b>{{ $pesos($venta['meta']) }}</b></span>
                    <span>Facturado <b>{{ $pesos($venta['facturado']) }}</b></span>
                    <span>Falta <b>{{ $pesos($venta['falta']) }}</b></span>
                    <span class="pie">{{ number_format($venta['avance'], 1, ',', '.') }}% de la meta</span>
                </div>

                <div class="barra" role="presentation">
                    <span style="width:{{ min(100, max(0, $venta['avance'])) }}%"></span>
                </div>

                <p class="nota">
                    Lo facturado sale de las ventas pagadas del año, convertidas a pesos a la
                    tasa del laboratorio. Es una equivalencia, no un extracto bancario.
                </p>
            </x-filament::section>
        @endif
    </div>

</x-filament-widgets::widget>
