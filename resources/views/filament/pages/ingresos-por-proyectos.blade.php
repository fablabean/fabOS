<x-filament-panels::page>

    @php
        $pesos = fn ($v) => config('fabos.money.symbol') . number_format((float) $v, 0, ',', '.');
        // En las tablas, el signo aparte, pegado a la izquierda, y la cifra a
        // la derecha: se lee la columna de números sin tropezar con el «$».
        $celda = fn ($v) => new \Illuminate\Support\HtmlString('<span class="m"><span class="s">' . e(config('fabos.money.symbol')) . '</span>' . number_format((float) $v, 0, ',', '.') . '</span>');
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
        .ipp .m{display:inline-flex;justify-content:space-between;gap:.6rem;min-width:7.5rem;font-variant-numeric:tabular-nums}
        .ipp .m .s{color:rgb(156 163 175)}
        .ipp button.dato .m{min-width:7.5rem}
        .ipp tr.total td{font-weight:700;border-top:2px solid rgba(128,128,128,.35)}
        .ipp .gris{color:rgb(107 114 128)}
        .ipp .interno{color:rgb(107 114 128);font-style:italic}
        .ipp td:first-letter{text-transform:uppercase}
        .ipp .barra{height:.55rem;border-radius:999px;background:rgba(128,128,128,.18);overflow:hidden}
        .ipp .barra i{display:block;height:100%;background:#D69E2E}
        .ipp .contra-meta{display:flex;flex-direction:column;gap:.35rem;margin-bottom:1rem;max-width:48rem}
        .ipp .contra-meta .fila{display:grid;grid-template-columns:5.5rem minmax(0,1fr) 15rem;align-items:center;gap:.75rem;font-size:.85rem}
        .ipp .contra-meta .pista{position:relative;height:.7rem;border-radius:4px;background:rgba(128,128,128,.14)}
        .ipp .contra-meta .pista.sin-fondo{background:none;height:1rem}
        .ipp .contra-meta .pista i{position:absolute;left:0;top:0;bottom:0;border-radius:0 4px 4px 0}
        .ipp .contra-meta .pista b.meta{position:absolute;top:-.25rem;bottom:-.25rem;width:2px;margin-left:-1px;background:currentColor}
        .ipp .contra-meta .pista.sin-fondo span{position:absolute;transform:translateX(-50%);font-size:.7rem;color:rgb(107 114 128)}
        .ipp .contra-meta .cifra-meta{font-variant-numeric:tabular-nums;white-space:nowrap}
        @media (max-width:640px){.ipp .contra-meta .fila{grid-template-columns:4.5rem minmax(0,1fr)}.ipp .contra-meta .cifra-meta{grid-column:2}}
        .ipp button.dato{all:unset;cursor:pointer;border-bottom:1px dotted currentColor}
        .ipp .ajuste summary{cursor:pointer;font-size:.9rem}
        .ipp .ajuste-cuerpo{margin-top:.8rem}
        .ipp .ajuste .realista{display:inline-flex;flex-direction:column;gap:.25rem;margin-bottom:.8rem;font-size:.85rem}
        .ipp .ajuste .realista input{width:7rem}
        .ipp .ajuste table{max-width:40rem}
        .ipp input.pct{width:4.2rem;text-align:right;padding:.15rem .35rem;border-radius:.35rem;border:1px solid rgba(128,128,128,.35);background:transparent;font-variant-numeric:tabular-nums}
        .ipp input.pct.baja{border-color:#D69E2E;background:color-mix(in srgb,#D69E2E 10%,transparent)}
        .ipp .sin-guardar{margin-left:.5rem;padding:.05rem .5rem;border-radius:999px;font-size:.7rem;font-weight:600;background:#D69E2E;color:#fff}
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
                                    @php $texto = $clave === 'n' ? $m[$clave] : $celda($m[$clave]); @endphp
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
                Optimista: el promedio por mes de lo observado ({{ $diasObservados }} días), para cada tipo de cliente,
                por el porcentaje de cada mes en «Ajustar la proyección». Realista: el {{ $ajuste['realista'] }}% de la
                optimista. Mientras haya proyectos sin valor, se queda corta.
            </x-slot>

            @include('filament.pages.ingresos-por-proyectos-grafica')

            {{-- Jugar con la proyección: cada cambio redibuja; solo queda al guardarlo. --}}
            <details class="ajuste" {{ $sinGuardar ? 'open' : '' }} style="margin-top:1rem">
                <summary>
                    <strong>Ajustar la proyección</strong>
                    <span class="gris">· cuánto pesa cada mes para cada tipo, en % de un mes normal</span>
                    @if ($sinGuardar) <span class="sin-guardar">sin guardar</span> @endif
                </summary>

                <div class="ajuste-cuerpo">
                    <label class="realista">
                        <span>Realista, en % de la optimista</span>
                        <input type="number" min="0" max="100" step="5" wire:model.live.debounce.400ms="ajuste.realista"
                               class="fi-input block rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">
                    </label>

                    <div class="tabla">
                        <table>
                            <thead>
                                <tr>
                                    <th>Mes</th>
                                    @foreach (\App\Services\Reports\IngresosPorProyectos::TIPOS_DEL_AJUSTE as $nombre) <th class="num">{{ $nombre }}</th> @endforeach
                                </tr>
                            </thead>
                            <tbody>
                            @foreach (range(1, 12) as $num)
                                <tr>
                                    <td>{{ \Illuminate\Support\Carbon::create(2000, $num, 1)->locale('es')->isoFormat('MMMM') }}</td>
                                    @foreach (\App\Services\Reports\IngresosPorProyectos::TIPOS_DEL_AJUSTE as $tipo => $nombre)
                                        <td class="num">
                                            <input type="number" min="0" max="300" step="5" aria-label="{{ $nombre }} en {{ $num }}"
                                                   wire:model.live.debounce.400ms="ajuste.meses.{{ $num }}.{{ $tipo }}"
                                                   class="pct {{ ($ajuste['meses'][$num][$tipo] ?? 100) < 100 ? 'baja' : '' }}">%
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div style="display:flex;flex-wrap:wrap;gap:.6rem;margin-top:.8rem">
                        <x-filament::button wire:click="guardarAjuste" icon="heroicon-o-check" :disabled="! $sinGuardar">Guardar este ajuste</x-filament::button>
                        <x-filament::button wire:click="volverAlGuardado" color="gray" :disabled="! $sinGuardar">Volver a lo guardado</x-filament::button>
                        <x-filament::button wire:click="ajusteDeFabrica" color="gray">Valores de fábrica</x-filament::button>
                    </div>
                </div>
            </details>

            @if ($meta)
                @php
                    // Una sola escala para la meta y los dos escenarios: la
                    // del mayor. La meta es una raya; cada barra, su largo.
                    $escala = max($meta['meta'], $meta['optimista'], $meta['realista'], 1);
                    $largo = fn ($v) => round($v / $escala * 100, 1);
                    $deLaMeta = fn ($v) => (int) round($v / $meta['meta'] * 100);
                @endphp
                <p style="margin:1rem 0 .5rem">
                    Meta de venta {{ $meta['ano'] }}: <strong>{{ $pesos($meta['meta']) }}</strong>. En los próximos doce meses:
                </p>
                <div class="contra-meta">
                    @foreach ([['Optimista', $meta['optimista'], 'var(--g-opt, #eb6834)'], ['Realista', $meta['realista'], 'var(--g-rea, #1baf7a)']] as [$nombre, $valor, $color])
                        <div class="fila">
                            <span class="nombre">{{ $nombre }}</span>
                            <div class="pista">
                                <i style="width:{{ $largo($valor) }}%;background:{{ $color }}"></i>
                                <b class="meta" style="left:{{ $largo($meta['meta']) }}%" title="Meta {{ $pesos($meta['meta']) }}"></b>
                            </div>
                            <span class="cifra-meta">{{ $pesos($valor) }} · <strong>{{ $deLaMeta($valor) }}%</strong> de la meta</span>
                        </div>
                    @endforeach
                    <div class="fila leyenda-meta"><span class="nombre"></span>
                        <div class="pista sin-fondo"><span style="left:{{ $largo($meta['meta']) }}%">meta</span></div><span></span>
                    </div>
                </div>
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
                            <td>{{ $m['nombre'] }}@if ($m['bajo']) <span class="gris">· mes bajo</span>@endif</td>
                            <td class="num"><strong>{{ $celda($m['optimista']) }}</strong></td>
                            <td class="num">{{ $celda($m['realista']) }}</td>
                            <td class="num">{{ $celda($m['acumulado_optimista']) }}</td>
                            <td class="num">{{ $celda($m['acumulado_realista']) }}</td>
                            <td class="num interno">{{ $celda($m['internos']) }}</td>
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
