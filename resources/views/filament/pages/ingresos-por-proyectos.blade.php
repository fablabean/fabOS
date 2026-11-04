<x-filament-panels::page>

    @php
        $pesos = fn ($v) => config('fabos.money.symbol') . number_format((float) $v, 0, ',', '.');
        $columnas = \App\Services\Reports\IngresosPorProyectos::COLUMNAS;
        // Cada cifra del histórico se abre: qué proyectos la forman.
        $abrir = fn (?string $mes, string $col) => 'mountAction(\'origen\', { mes: ' . ($mes ? "'" . $mes . "'" : 'null') . ', columna: \'' . $col . '\' })';
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
        .ipp button.dato{all:unset;cursor:pointer;border-bottom:1px dotted currentColor}
        .ipp button.dato:hover,.ipp button.dato:focus-visible{color:#B7791F}
    </style>

    <div class="ipp">

        <x-filament::section>
            <x-slot name="heading">Cómo se cuenta</x-slot>
            <x-slot name="description">
                Proyectos que llegaron a ejecución, a pago o a cierre, sin descartarse ni estar en pausa (si se reanudan, vuelven a sumar). Vale lo acordado, o lo
                estimado mientras no haya acuerdo; lo que no tiene ninguno de los dos no suma y se lista abajo.
                <strong>Facturable</strong> es el valor de lo hecho para estudiantes, la Universidad y de fuera —lo que se cobra, no lo que ya entró: eso está en Pagos—; <strong>internos</strong> es
                el valor de lo que el laboratorio hizo sin cobrar, aparte. Las alianzas no entran.
            </x-slot>

            <div class="rejilla">
                <div class="cifra"><b>{{ $pesos($totales['cobrado']) }}</b><span>facturable desde {{ $desde?->locale('es')->isoFormat('D MMM YYYY') ?? '—' }}</span></div>
                <div class="cifra"><b>{{ $pesos($totales['internos']) }}</b><span>en proyectos internos</span></div>
                <div class="cifra"><b>{{ $pesos($promedio['cobrado']) }}</b><span>facturable por mes, en promedio</span></div>
                <div class="cifra"><b>{{ $totales['n'] }}</b><span>proyectos ejecutados · {{ $sinValor->count() }} sin valor</span></div>
            </div>

            <div style="margin-top:1rem">
                <x-filament::button wire:click="descargar" icon="heroicon-o-arrow-down-tray">Descargar para Excel</x-filament::button>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Mes a mes</x-slot>
            <x-slot name="description">Pulsa cualquier cifra para ver de qué proyectos sale.</x-slot>
            <div class="tabla">
                <table>
                    <thead>
                        <tr>
                            <th>Mes</th>
                            @foreach ($columnas as $nombre) <th class="num">{{ $nombre }}</th> @endforeach
                            <th class="num">Facturable</th>
                            <th class="num">Internos</th>
                            <th class="num">Con internos</th>
                            <th class="num">Proyectos</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ([...$meses, ['mes' => null, 'nombre' => 'Total'] + $totales] as $m)
                        <tr class="{{ $m['mes'] ? '' : 'total' }}">
                            <td>{{ $m['nombre'] }}</td>
                            @foreach ([...array_keys($columnas), 'cobrado', 'internos', 'total', 'n'] as $clave)
                                <td class="num {{ $clave === 'internos' ? 'interno' : '' }}">
                                    @php $texto = $clave === 'n' ? $m[$clave] : $pesos($m[$clave]); @endphp
                                    @if ($m[$clave])
                                        <button type="button" class="dato" wire:click="{{ $abrir($m['mes'], $clave) }}"
                                                title="Ver de qué proyectos sale">
                                            @if ($clave === 'cobrado' && $m['mes'])<strong>{{ $texto }}</strong>@else{{ $texto }}@endif
                                        </button>
                                    @else
                                        <span class="gris">{{ $texto }}</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Proyección a doce meses</x-slot>
            <x-slot name="description">
                Optimista: el promedio facturable por mes de lo observado ({{ $diasObservados }} días). Realista: la mitad.
                En diciembre, enero, junio y julio —intersemestral y fin de año— casi no se vende: esos meses cuentan
                el {{ (int) (\App\Services\Reports\IngresosPorProyectos::FACTOR_MES_BAJO * 100) }}% de uno normal.
                Se recalcula sola; mientras haya proyectos sin valor, se queda corta.
            </x-slot>

            @include('filament.pages.ingresos-por-proyectos-grafica')

            @if ($meta)
                @php
                    $avOpt = min(100, (int) round($meta['optimista'] / $meta['meta'] * 100));
                    $avRea = min(100, (int) round($meta['realista'] / $meta['meta'] * 100));
                @endphp
                <p style="margin:1rem 0 .4rem">
                    Meta de venta {{ $meta['ano'] }}: <strong>{{ $pesos($meta['meta']) }}</strong>. En los próximos doce meses:
                    optimista <strong>{{ $pesos($meta['optimista']) }}</strong> ({{ $avOpt }}%),
                    realista <strong>{{ $pesos($meta['realista']) }}</strong> ({{ $avRea }}%).
                </p>
                <div class="barra" style="margin-bottom:.3rem" title="Optimista"><i style="width:{{ $avOpt }}%"></i></div>
                <div class="barra" style="margin-bottom:1rem" title="Realista"><i style="width:{{ $avRea }}%;background:#1baf7a"></i></div>
            @endif

            <div class="tabla">
                <table>
                    <thead>
                        <tr>
                            <th>Mes</th>
                            <th class="num">Optimista</th>
                            <th class="num">Realista</th>
                            <th class="num">Acumulado optimista</th>
                            <th class="num">Acumulado realista</th>
                            <th class="num">Internos</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ($proyeccion as $m)
                        <tr>
                            <td>{{ $m['nombre'] }}@if ($m['bajo']) <span class="gris">· temporada baja</span>@endif</td>
                            <td class="num"><strong>{{ $pesos($m['optimista']) }}</strong></td>
                            <td class="num">{{ $pesos($m['realista']) }}</td>
                            <td class="num">{{ $pesos($m['acumulado_optimista']) }}</td>
                            <td class="num">{{ $pesos($m['acumulado_realista']) }}</td>
                            <td class="num interno">{{ $pesos($m['internos']) }}</td>
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
