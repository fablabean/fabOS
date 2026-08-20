<x-filament-panels::page>

    @php
        $moneda = config('fabos.currency.code');
        $unidades = config('fabos.currency.minor_units');
        $simbolo = config('fabos.money.symbol');

        $horas = function (int $minutos) {
            $h = intdiv($minutos, 60);
            $m = $minutos % 60;

            return $h ? $h . ' h' . ($m ? " {$m} min" : '') : $m . ' min';
        };
        $fbc = fn ($menor) => number_format($menor / $unidades, 2, ',', '.');
        $pesos = fn ($v) => $simbolo . number_format((float) $v, 0, ',', '.');
    @endphp

    {{-- Estilos propios y no utilidades de Tailwind: el CSS de Filament viene
         compilado con un conjunto fijo de clases, asi que las rejillas de una
         pagina a medida no se aplicarian y todo quedaria en una columna. --}}
    <style>
        .inf{display:flex;flex-direction:column;gap:1.5rem}
        .inf .rejilla{display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(10rem,1fr))}
        .inf .filtros{display:flex;flex-wrap:wrap;align-items:flex-end;gap:1rem}
        .inf .filtros label{font-size:.85rem}
        .inf .filtros label span{display:block;margin-bottom:.25rem;color:rgb(107 114 128)}
        .inf table{width:100%;font-size:.86rem;border-collapse:collapse;margin-top:.6rem}
        .inf th,.inf td{text-align:left;padding:.35rem .5rem;border-bottom:1px solid rgba(128,128,128,.2)}
        .inf th{font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;color:rgb(107 114 128)}
        .inf td.num,.inf th.num{text-align:right;white-space:nowrap}
        .inf .nota{font-size:.82rem;color:rgb(107 114 128);margin-top:.6rem}
        .inf .cifra b{display:block;font-size:1.7rem;letter-spacing:-.02em;line-height:1.1}
        .inf .cifra span{font-size:.78rem;color:rgb(107 114 128)}
    </style>

    <div class="inf">

        <x-filament::section>
            <x-slot name="heading">Periodo</x-slot>
            <x-slot name="description">
                El informe se calcula al abrirlo, de los mismos datos con los que opera el
                laboratorio. No hay una tabla de estadísticas que alguien deba alimentar.
            </x-slot>

            <div class="filtros">
                <label class="text-sm">
                    <span class="block mb-1 text-gray-500 dark:text-gray-400">Desde</span>
                    <input type="date" wire:model.live="desde"
                           class="fi-input block rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">
                </label>

                <label class="text-sm">
                    <span class="block mb-1 text-gray-500 dark:text-gray-400">Hasta</span>
                    <input type="date" wire:model.live="hasta"
                           class="fi-input block rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">
                </label>

                <x-filament::button wire:click="mesAnterior" color="gray">
                    Cerrar el mes pasado
                </x-filament::button>

                <x-filament::button tag="a" href="{{ $enlace }}" target="_blank" icon="heroicon-o-printer">
                    Ver para imprimir
                </x-filament::button>
            </div>
        </x-filament::section>

        {{-- ------------------------------------------------------------ uso --}}
        <x-filament::section>
            <x-slot name="heading">Uso del laboratorio</x-slot>

            <div class="rejilla">
                <div class="cifra">
                    <b>{{ $informe->uso['completadas'] }}</b>
                    <span>sesiones completadas</span>
                </div>
                <div class="cifra">
                    <b>{{ $horas($informe->uso['minutos_usados']) }}</b>
                    <span>de uso real de equipos</span>
                </div>
                <div class="cifra">
                    <b>{{ $informe->personas['atendidas'] }}</b>
                    <span>personas atendidas</span>
                </div>
                <div class="cifra">
                    <b>{{ $informe->aprovechamiento() !== null ? $informe->aprovechamiento() . '%' : '—' }}</b>
                    <span>del tiempo reservado se aprovechó</span>
                </div>
            </div>

            <p class="nota">
                Las horas se cuentan desde la llegada hasta la salida registradas en el equipo,
                no desde el bloque reservado. Si el aprovechamiento baja, es que la agenda se
                está bloqueando con reservas a las que nadie llega
                ({{ $informe->uso['no_show'] }} en este periodo,
                {{ $informe->uso['canceladas'] }} canceladas a tiempo).
            </p>

            @if ($informe->porArea->isNotEmpty())
                <table>
                    <thead>
                        <tr><th>Área</th><th class="num">Sesiones</th><th class="num">Personas</th>
                            <th class="num">Uso real</th></tr>
                    </thead>
                    <tbody>
                    @foreach ($informe->porArea as $area => $datos)
                        <tr>
                            <td>{{ $area }}</td>
                            <td class="num">{{ $datos['reservas'] }}</td>
                            <td class="num">{{ $datos['personas'] }}</td>
                            <td class="num">{{ $horas($datos['minutos']) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @else
                <p class="nota">Sin sesiones completadas en este periodo.</p>
            @endif
        </x-filament::section>

        {{-- ------------------------------------------------------- equipos --}}
        @if ($informe->equiposMasUsados->isNotEmpty())
            <x-filament::section collapsible collapsed>
                <x-slot name="heading">Equipos más usados</x-slot>
                <table>
                    <thead><tr><th>Equipo</th><th class="num">Sesiones</th><th class="num">Uso real</th></tr></thead>
                    <tbody>
                    @foreach ($informe->equiposMasUsados as $e)
                        <tr>
                            <td>{{ $e['nombre'] }}</td>
                            <td class="num">{{ $e['reservas'] }}</td>
                            <td class="num">{{ $horas($e['minutos']) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </x-filament::section>
        @endif

        {{-- ------------------------------------------------------ comunidad --}}
        <x-filament::section>
            <x-slot name="heading">Comunidad y formación</x-slot>

            <table>
                <tbody>
                    <tr><td>Personas que usaron el laboratorio</td>
                        <td class="num">{{ $informe->personas['atendidas'] }}</td></tr>
                    <tr><td>Cuentas nuevas</td>
                        <td class="num">{{ $informe->personas['nuevas'] }}</td></tr>
                    <tr><td>Sesiones con acompañamiento del equipo</td>
                        <td class="num">{{ $informe->uso['con_acompanante'] }}</td></tr>
                    <tr><td>Habilitaciones otorgadas</td>
                        <td class="num">{{ $informe->formacion['certifabs'] }}
                            a {{ $informe->formacion['personas'] }} personas</td></tr>
                </tbody>
            </table>

            @if ($informe->personas['por_categoria']->isNotEmpty())
                <table>
                    <thead><tr><th>Categoría</th><th class="num">Personas</th></tr></thead>
                    <tbody>
                    @foreach ($informe->personas['por_categoria'] as $categoria => $total)
                        <tr><td>{{ $categoria }}</td><td class="num">{{ $total }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>

        {{-- --------------------------------------------------- mantenimiento --}}
        <x-filament::section>
            <x-slot name="heading">Mantenimiento</x-slot>
            <table>
                <tbody>
                    <tr><td>Órdenes abiertas en el periodo</td>
                        <td class="num">{{ $informe->mantenimiento['abiertas'] }}
                            ({{ $informe->mantenimiento['correctivas'] }} correctivas,
                            {{ $informe->mantenimiento['preventivas'] }} preventivas)</td></tr>
                    <tr><td>Órdenes cerradas</td>
                        <td class="num">{{ $informe->mantenimiento['cerradas'] }}</td></tr>
                    <tr><td>Tiempo de equipos fuera de servicio</td>
                        <td class="num">{{ $horas($informe->mantenimiento['minutos_paro']) }}</td></tr>
                    <tr><td>Órdenes todavía abiertas hoy</td>
                        <td class="num">{{ $informe->mantenimiento['sin_resolver'] }}</td></tr>
                </tbody>
            </table>
        </x-filament::section>

        {{-- ------------------------------------------------------- finanzas --}}
        <x-filament::section>
            <x-slot name="heading">{{ config('fabos.currency.name') }}s y compras</x-slot>

            <table>
                <tbody>
                    <tr><td>Emitido en el periodo</td>
                        <td class="num">{{ $fbc($informe->finanzas['emitido']) }} {{ $moneda }}</td></tr>
                    <tr><td>Consumo causado por uso de equipos</td>
                        <td class="num">{{ $fbc($informe->finanzas['causado']) }} {{ $moneda }}</td></tr>
                    <tr><td>Ventas de la tienda ({{ $informe->finanzas['n_ventas'] }})</td>
                        <td class="num">{{ $fbc($informe->finanzas['ventas']) }} {{ $moneda }}</td></tr>
                    <tr><td>Retenido hoy en garantías</td>
                        <td class="num">{{ $fbc($informe->finanzas['retenido']) }} {{ $moneda }}</td></tr>
                </tbody>
            </table>

            @if ($informe->compras['presupuestos']->isNotEmpty())
                <table>
                    <thead>
                        <tr><th>Presupuesto</th><th class="num">Aprobado</th><th class="num">Comprometido</th>
                            <th class="num">Ejecutado</th><th class="num">Disponible</th></tr>
                    </thead>
                    <tbody>
                    @foreach ($informe->compras['presupuestos'] as $p)
                        <tr>
                            <td>{{ $p->name }} {{ $p->year }}</td>
                            <td class="num">{{ $pesos($p->amount) }}</td>
                            <td class="num">{{ $pesos($p->comprometido()) }}</td>
                            <td class="num">{{ $pesos($p->ejecutado()) }}</td>
                            <td class="num">{{ $pesos($p->disponible()) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @else
                <p class="nota">No hay presupuestos vigentes cargados.</p>
            @endif
        </x-filament::section>

    </div>

</x-filament-panels::page>
