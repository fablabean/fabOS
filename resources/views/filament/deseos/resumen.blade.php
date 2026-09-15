<x-filament-widgets::widget>

    {{-- Estilos propios: el CSS de Filament viene compilado y no trae las
         utilidades de rejilla que usaria una tarjeta a medida. --}}
    <style>
        .des .rejilla { display:grid; gap:1rem;
                        grid-template-columns:repeat(auto-fit,minmax(10.5rem,1fr)); }
        .des dt { font-size:.78rem; color:rgb(107 114 128); }
        .des dd { margin:0; font-size:1.45rem; font-weight:600; letter-spacing:-.02em;
                  line-height:1.25; }
        .des dd .pie { display:block; font-size:.72rem; font-weight:400;
                       color:rgb(107 114 128); letter-spacing:0; }
        .des table { width:100%; border-collapse:collapse; margin-top:1.1rem; font-size:.85rem; }
        .des th { text-align:left; font-weight:500; font-size:.72rem; text-transform:uppercase;
                  letter-spacing:.04em; color:rgb(107 114 128); padding:0 .5rem .35rem 0; }
        .des td { padding:.35rem .5rem .35rem 0; border-top:1px solid rgb(229 231 235); }
        .des td.cifra, .des th.cifra { text-align:right; font-variant-numeric:tabular-nums; }
        .des tfoot td { font-weight:600; border-top:2px solid rgb(209 213 219); }
        .des .pordecidir { color:rgb(180 83 9); }
        .des .areas { margin-top:.9rem; }
        .des .areas summary { font-size:.8rem; color:rgb(107 114 128); cursor:pointer; }
        .des .nota { font-size:.78rem; color:rgb(107 114 128); margin-top:.7rem; }
        .des .aviso { font-size:.78rem; color:rgb(180 83 9); margin-top:.7rem; }
        .dark .des .pordecidir { color:rgb(252 211 77); }
        .dark .des td { border-top-color:rgb(55 65 81); }
        .dark .des tfoot td { border-top-color:rgb(75 85 99); }
        .dark .des .aviso { color:rgb(252 211 77); }
    </style>

    @php
        $resumen = $this->getResumen();

        $simbolo = config('fabos.money.symbol');
        $pesos = fn (int $v) => $simbolo . number_format($v, 0, ',', '.');
        $impuesto = round($resumen['tasa'] * 100);
    @endphp

    <div class="des">
        <x-filament::section>
            <x-slot name="heading">Lo que la lista pide para {{ $resumen['anio'] }}</x-slot>
            <x-slot name="description">
                {{ $resumen['cuantos'] }}
                {{ $resumen['cuantos'] === 1 ? 'deseo todavía sin comprar' : 'deseos todavía sin comprar' }}.
                Es la cifra con la que se propone el presupuesto del año.
            </x-slot>

            <dl class="rejilla">
                <div>
                    <dt>Estimado</dt>
                    <dd>
                        {{ $pesos($resumen['estimado']) }}
                        <span class="pie">sin impuesto</span>
                    </dd>
                </div>

                <div>
                    <dt>Con impuesto</dt>
                    <dd>
                        {{ $pesos($resumen['conImpuesto']) }}
                        <span class="pie">+{{ $impuesto }}%, como trabaja compras</span>
                    </dd>
                </div>

                <div>
                    <dt>Sin cotizar</dt>
                    <dd>
                        {{ $resumen['sinEstimar'] }}
                        <span class="pie">
                            {{ $resumen['sinEstimar'] === 1 ? 'deseo sin precio' : 'deseos sin precio' }}
                        </span>
                    </dd>
                </div>
            </dl>

            {{-- Por rubro primero: es el reparto que se le entrega a la
                 Universidad, y con el que nace un presupuesto por cada uno. --}}
            @if (count($resumen['rubros']) > 0)
                <table>
                    <thead>
                        <tr>
                            <th>Rubro del presupuesto</th>
                            <th class="cifra">Deseos</th>
                            <th class="cifra">Estimado</th>
                            <th class="cifra">Con impuesto</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($resumen['rubros'] as $fila)
                            <tr>
                                <td>
                                    @if ($fila['rubro'])
                                        {{ $fila['rubro'] }}
                                    @else
                                        <span class="pordecidir">Sin rubro todavía</span>
                                    @endif
                                </td>
                                <td class="cifra">
                                    {{ $fila['cuantos'] }}
                                    @if ($fila['sinEstimar'] > 0)
                                        <span class="pie">{{ $fila['sinEstimar'] }} sin cotizar</span>
                                    @endif
                                </td>
                                <td class="cifra">{{ $pesos($fila['estimado']) }}</td>
                                <td class="cifra">{{ $pesos($fila['conImpuesto']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>Total</td>
                            <td class="cifra">{{ $resumen['cuantos'] }}</td>
                            <td class="cifra">{{ $pesos($resumen['estimado']) }}</td>
                            <td class="cifra">{{ $pesos($resumen['conImpuesto']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            @endif

            @if (count($resumen['areas']) > 1)
                {{-- El área responde otra pregunta —a quién le hace falta—, así
                     que va debajo y plegada: leerlas a la vez confunde dos
                     repartos del mismo dinero. --}}
                <details class="areas">
                    <summary>Y por área, a quién le hace falta</summary>
                    <table>
                        <thead>
                            <tr>
                                <th>Área</th>
                                <th class="cifra">Deseos</th>
                                <th class="cifra">Estimado</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($resumen['areas'] as $fila)
                                <tr>
                                    <td>{{ $fila['area'] ?? 'Todo el laboratorio' }}</td>
                                    <td class="cifra">{{ $fila['cuantos'] }}</td>
                                    <td class="cifra">{{ $pesos($fila['estimado']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </details>
            @endif

            @if ($resumen['sinEstimar'] > 0)
                {{-- A la vista, no escondido: quien tome el total por completo
                     va a pedir de menos y no va a saber por que. --}}
                <p class="aviso">
                    {{ $resumen['sinEstimar'] }}
                    {{ $resumen['sinEstimar'] === 1 ? 'deseo no tiene precio' : 'deseos no tienen precio' }}
                    y no {{ $resumen['sinEstimar'] === 1 ? 'entra' : 'entran' }} en estas cifras.
                    Cotizarlo{{ $resumen['sinEstimar'] === 1 ? '' : 's' }} antes de presupuestar
                    es lo que evita pedir de menos.
                </p>
            @endif

            <p class="nota">
                Cuenta lo que sigue pendiente —en la lista y ya pedido—; lo recibido no se vuelve a
                presupuestar y lo descartado se decidió que no. El impuesto es el del laboratorio:
                lo que no lo lleve se corrige en su solicitud.
            </p>
        </x-filament::section>
    </div>

</x-filament-widgets::widget>
