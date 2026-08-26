<x-filament-panels::page>
    {{ $this->form }}

    @php
        $c = $this->cotizacion;

        $unidades = (int) config('fabos.currency.minor_units');
        $tasa = (int) config('fabos.currency.peso_rate');
        $simbolo = config('fabos.money.symbol');

        $fbc = fn (int $menor) => number_format($menor / $unidades, 2, ',', '.');
        $pesos = fn (int $menor) => $simbolo . number_format(round($menor / $unidades * $tasa), 0, ',', '.');
    @endphp

    @if (! $c)
        <div class="ct vacio">
            Elige una máquina y cuánto tarda. El cálculo aparece aquí mismo, sin guardar nada.
        </div>
    @else
        <div class="ct">
            <div class="cabeza">
                <div>
                    <div class="que">{{ $c['equipo']->name }}</div>
                    <div class="quien">
                        {{ $c['minutos'] }} min
                        · {{ $c['persona']?->name ?? 'tarifa de lista' }}
                        @if ($c['persona']?->category)
                            · {{ $c['persona']->category->name }}
                        @endif
                    </div>
                </div>

                <div class="cifra">
                    <div class="pesos">{{ $pesos($c['total']) }}</div>
                    <div class="fbc">{{ $fbc($c['total']) }} FabCoins</div>
                </div>
            </div>

            @if (empty($c['lineas']))
                <p class="aviso">
                    Esta máquina no tiene tarifa asociada, así que el sistema no cobraría nada por ella.
                    Se arregla en <strong>Finanzas → Tarifas</strong>.
                </p>
            @else
                <table>
                    <tbody>
                        @foreach ($c['lineas'] as $linea)
                            <tr>
                                <th>
                                    {{ $linea['concepto'] }}
                                    @if ($linea['detalle'])
                                        <div class="quien">{{ $linea['detalle'] }}</div>
                                    @endif
                                </th>
                                <td>{{ $pesos($linea['importe']) }}</td>
                            </tr>
                        @endforeach

                        <tr class="total">
                            <th>Total</th>
                            <td>{{ $pesos($c['total']) }}</td>
                        </tr>

                        @if ($c['deposito'] > 0)
                            <tr>
                                <th>
                                    Se compromete al reservar
                                    <div class="quien">el resto se liquida al cerrar</div>
                                </th>
                                <td>{{ $pesos($c['deposito']) }}</td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            @endif

            @if ($c['supuesta'])
                <p class="aviso">
                    Alguna de estas tarifas es <strong>supuesta</strong>: se sembró para que el
                    sistema funcionara, no la decidió el laboratorio. Sirve para orientar, no
                    para comprometer un precio con alguien de fuera.
                </p>
            @endif

            <p class="pie">
                Sale de la misma tarifa que aplicará la reserva: mismo redondeo al bloque de
                facturación, mismo factor de categoría, mismo mínimo. Si este número y el que
                cobra el sistema salieran de dos sitios distintos, tarde o temprano dirían
                cosas distintas.
            </p>
        </div>
    @endif

    {{-- Rejilla propia: el CSS de Filament viene compilado y sus utilidades
         responsivas no están disponibles en páginas a medida. --}}
    <style>
        .ct { border: 1px solid rgb(228 228 231); border-radius: .75rem; padding: 1.25rem;
              background: white; margin-top: 1.5rem; }
        .ct.vacio { color: rgb(113 113 122); font-size: .9rem; }
        .ct .cabeza { display: flex; justify-content: space-between; align-items: flex-start;
                      gap: 1rem; flex-wrap: wrap; padding-bottom: 1rem;
                      border-bottom: 1px solid rgb(228 228 231); margin-bottom: .5rem; }
        .ct .que { font-weight: 600; font-size: 1.05rem; }
        .ct .quien { font-size: .78rem; color: rgb(113 113 122); }
        .ct .cifra { text-align: right; }
        .ct .cifra .pesos { font-size: 1.6rem; font-weight: 700; line-height: 1.1; }
        .ct .cifra .fbc { font-size: .78rem; color: rgb(113 113 122); }
        .ct table { width: 100%; border-collapse: collapse; }
        .ct th, .ct td { text-align: left; padding: .55rem .2rem;
                         border-bottom: 1px solid rgb(244 244 245); font-weight: 400; }
        .ct td { text-align: right; white-space: nowrap; }
        .ct tr.total th, .ct tr.total td { font-weight: 700; }
        .ct .aviso { margin-top: .9rem; font-size: .85rem; padding: .7rem .9rem;
                     border-left: 3px solid rgb(217 119 6); background: rgb(254 252 232);
                     border-radius: .35rem; }
        .ct .pie { margin-top: .9rem; font-size: .78rem; color: rgb(113 113 122); }

        @media (prefers-color-scheme: dark) {
            .ct { background: rgb(24 24 27); border-color: rgb(63 63 70); }
            .ct .cabeza { border-bottom-color: rgb(63 63 70); }
            .ct th, .ct td { border-bottom-color: rgb(39 39 42); }
            .ct .aviso { background: rgb(41 37 20); }
        }
    </style>
</x-filament-panels::page>
