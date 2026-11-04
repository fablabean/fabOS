<x-filament-panels::page>

    @php
        $pesos = fn ($v) => config('fabos.money.symbol') . number_format((float) $v, 0, ',', '.');
        $columnas = \App\Services\Reports\IngresosPorProyectos::COLUMNAS;
    @endphp

    {{-- Estilos propios: el CSS de Filament viene compilado con un conjunto
         fijo de clases y las de una página a medida no se aplicarían. --}}
    <style>
        .ipp{display:flex;flex-direction:column;gap:1.5rem}
        .ipp .rejilla{display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(11rem,1fr))}
        .ipp .cifra b{display:block;font-size:1.5rem;letter-spacing:-.02em;line-height:1.1}
        .ipp .cifra span{font-size:.78rem;color:rgb(107 114 128)}
        .ipp .tabla{overflow-x:auto}
        .ipp table{width:100%;font-size:.86rem;border-collapse:collapse}
        .ipp th,.ipp td{text-align:left;padding:.4rem .5rem;border-bottom:1px solid rgba(128,128,128,.2);vertical-align:top}
        .ipp th{font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;color:rgb(107 114 128)}
        .ipp td.num,.ipp th.num{text-align:right;white-space:nowrap}
        .ipp tr.total td{font-weight:700;border-top:2px solid rgba(128,128,128,.35)}
        .ipp .gris{color:rgb(107 114 128)}
        .ipp .interno{color:rgb(107 114 128);font-style:italic}
        .ipp td:first-letter{text-transform:uppercase}
        .ipp .barra{height:.55rem;border-radius:999px;background:rgba(128,128,128,.18);overflow:hidden}
        .ipp .barra i{display:block;height:100%;background:#D69E2E}
    </style>

    <div class="ipp">

        <x-filament::section>
            <x-slot name="heading">Cómo se cuenta</x-slot>
            <x-slot name="description">
                Proyectos que llegaron a ejecución, a pago o a cierre, sin descartarse. Vale lo acordado, o lo
                estimado mientras no haya acuerdo; lo que no tiene ninguno de los dos no suma y se lista abajo.
                <strong>Cobrado</strong> es lo de estudiantes, la Universidad y de fuera; <strong>internos</strong> es
                el valor de lo que el laboratorio hizo sin cobrar, aparte. Las alianzas no entran.
            </x-slot>

            <div class="rejilla">
                <div class="cifra"><b>{{ $pesos($totales['cobrado']) }}</b><span>cobrado desde {{ $desde?->locale('es')->isoFormat('D MMM YYYY') ?? '—' }}</span></div>
                <div class="cifra"><b>{{ $pesos($totales['internos']) }}</b><span>en proyectos internos</span></div>
                <div class="cifra"><b>{{ $pesos($promedio['cobrado']) }}</b><span>cobrado por mes, en promedio</span></div>
                <div class="cifra"><b>{{ $totales['n'] }}</b><span>proyectos ejecutados · {{ $sinValor->count() }} sin valor</span></div>
            </div>

            <div style="margin-top:1rem">
                <x-filament::button wire:click="descargar" icon="heroicon-o-arrow-down-tray">Descargar para Excel</x-filament::button>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Mes a mes</x-slot>
            <div class="tabla">
                <table>
                    <thead>
                        <tr>
                            <th>Mes</th>
                            @foreach ($columnas as $nombre) <th class="num">{{ $nombre }}</th> @endforeach
                            <th class="num">Cobrado</th>
                            <th class="num">Internos</th>
                            <th class="num">Con internos</th>
                            <th class="num">Proyectos</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ($meses as $m)
                        <tr>
                            <td>{{ $m['nombre'] }}</td>
                            @foreach ($columnas as $clave => $nombre) <td class="num">{{ $pesos($m[$clave]) }}</td> @endforeach
                            <td class="num"><strong>{{ $pesos($m['cobrado']) }}</strong></td>
                            <td class="num interno">{{ $pesos($m['internos']) }}</td>
                            <td class="num">{{ $pesos($m['total']) }}</td>
                            <td class="num">{{ $m['n'] }}</td>
                        </tr>
                    @endforeach
                        <tr class="total">
                            <td>Total</td>
                            @foreach ($columnas as $clave => $nombre) <td class="num">{{ $pesos($totales[$clave]) }}</td> @endforeach
                            <td class="num">{{ $pesos($totales['cobrado']) }}</td>
                            <td class="num interno">{{ $pesos($totales['internos']) }}</td>
                            <td class="num">{{ $pesos($totales['total']) }}</td>
                            <td class="num">{{ $totales['n'] }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Proyección a doce meses</x-slot>
            <x-slot name="description">
                El promedio por mes de lo observado ({{ $diasObservados }} días), repetido. Es una línea de base: si
                cambia el ritmo, cambia sola al recalcular. Mientras haya proyectos sin valor, se queda corta.
            </x-slot>

            @if ($meta)
                @php $avance = min(100, (int) round($meta['proyectado'] / $meta['meta'] * 100)); @endphp
                <p style="margin:0 0 .4rem">
                    Meta de venta {{ $meta['ano'] }}: <strong>{{ $pesos($meta['meta']) }}</strong>.
                    Al ritmo de hoy, doce meses dan <strong>{{ $pesos($meta['proyectado']) }}</strong> ({{ $avance }}%).
                </p>
                <div class="barra" style="margin-bottom:1rem"><i style="width:{{ $avance }}%"></i></div>
            @endif

            <div class="tabla">
                <table>
                    <thead>
                        <tr>
                            <th>Mes</th>
                            @foreach ($columnas as $nombre) <th class="num">{{ $nombre }}</th> @endforeach
                            <th class="num">Cobrado</th>
                            <th class="num">Internos</th>
                            <th class="num">Acumulado cobrado</th>
                            <th class="num">Acumulado con internos</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ($proyeccion as $m)
                        <tr>
                            <td>{{ $m['nombre'] }}</td>
                            @foreach ($columnas as $clave => $nombre) <td class="num">{{ $pesos($m[$clave]) }}</td> @endforeach
                            <td class="num"><strong>{{ $pesos($m['cobrado']) }}</strong></td>
                            <td class="num interno">{{ $pesos($m['internos']) }}</td>
                            <td class="num">{{ $pesos($m['acumulado_cobrado']) }}</td>
                            <td class="num">{{ $pesos($m['acumulado_total']) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <x-filament::section collapsible :collapsed="$sinValor->count() > 15">
            <x-slot name="heading">Sin valor registrado ({{ $sinValor->count() }})</x-slot>
            <x-slot name="description">
                Ejecutados sin valor acordado ni estimado: no suman. Ponerles el valor en su ficha
                (Compromisos → Valor estimado o acordado) los mete en las tablas de arriba.
            </x-slot>

            @if ($sinValor->isEmpty())
                <p class="text-sm gris">Todos los proyectos ejecutados tienen valor.</p>
            @else
                <div class="tabla">
                    <table>
                        <thead><tr><th>Código</th><th>Proyecto</th><th>Cliente</th><th>Etapa</th><th>Responsable</th><th>Mes</th></tr></thead>
                        <tbody>
                        @foreach ($sinValor as $p)
                            <tr>
                                <td><a class="underline" href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('edit', ['record' => $p]) }}">{{ $p->code }}</a></td>
                                <td>{{ $p->name }}</td>
                                <td>{{ \App\Models\Project::CLIENTES[$p->client_kind] ?? $p->client_kind }}{{ $p->is_internal ? ' · interno' : '' }}</td>
                                <td>{{ \App\Models\Project::ETAPAS[$p->stage] ?? $p->stage }}</td>
                                <td>{{ $p->lead?->name ?? '—' }}</td>
                                <td>{{ \App\Services\Reports\IngresosPorProyectos::mesDe($p) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
