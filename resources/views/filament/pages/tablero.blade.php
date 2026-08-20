<x-filament-panels::page>

    @php
        $pesos = fn ($v) => config('fabos.money.symbol') . number_format((float) $v, 0, ',', '.');
        $horas = function (int $minutos) {
            $h = intdiv($minutos, 60);

            return $h ? $h . ' h' : $minutos . ' min';
        };
        $tope = max(1, $tendencia->max('minutos'));
        $hayUso = $tendencia->sum('minutos') > 0;
    @endphp

    {{-- Estilos propios y no utilidades de Tailwind: el CSS de Filament viene
         compilado con un conjunto fijo de clases, así que las rejillas de una
         página a medida no se aplicarían y todo quedaría en una columna. --}}
    <style>
        .tb{display:flex;flex-direction:column;gap:1.5rem}
        .tb .rejilla{
            display:grid;gap:1rem;
            grid-template-columns:repeat(auto-fit,minmax(9rem,1fr));
        }
        .tb .cifra b{display:block;font-size:1.9rem;letter-spacing:-.03em;line-height:1.15}
        .tb .cifra span{display:block;font-size:.78rem;color:rgb(107 114 128);line-height:1.3}
        .tb .nota{font-size:.82rem;color:rgb(107 114 128);margin-top:.8rem}

        .tb .alerta{
            display:flex;align-items:center;gap:1rem;padding:.75rem .9rem;border-radius:8px;
            border:1px solid rgba(128,128,128,.25);margin-bottom:.5rem;
            text-decoration:none;color:inherit;transition:border-color .15s ease;
        }
        .tb .alerta:hover{border-color:rgb(var(--primary-500))}
        .tb .alerta b{font-size:1.5rem;min-width:2.75rem;text-align:center;line-height:1}
        .tb .alerta .q{display:block;font-weight:600}
        .tb .alerta .d{display:block;font-size:.82rem;color:rgb(107 114 128)}
        .tb .danger b{color:#dc2626}
        .tb .warning b{color:#b45309}
        .tb .info b{color:rgb(var(--primary-600))}

        .tb .barras{display:flex;align-items:flex-end;gap:.5rem;height:9rem}
        .tb .barras .col{
            flex:1;display:flex;flex-direction:column;justify-content:flex-end;
            align-items:center;gap:.35rem;height:100%;
        }
        .tb .barras i{
            display:block;width:100%;border-radius:4px 4px 0 0;min-height:3px;
            background:rgb(var(--primary-500));opacity:.8;
        }
        .tb .barras small{font-size:.68rem;color:rgb(107 114 128);white-space:nowrap}
    </style>

    <div class="tb">

        {{-- ------------------------------------------------------- ahora --}}
        <x-filament::section>
            <x-slot name="heading">Ahora mismo</x-slot>

            <div class="rejilla">
                <div class="cifra">
                    <b>{{ $ahora['en_uso'] }}</b>
                    <span>equipos en uso</span>
                </div>
                <div class="cifra">
                    <b>{{ $ahora['equipos_total'] - $ahora['en_mantenimiento'] }}</b>
                    <span>de {{ $ahora['equipos_total'] }} disponibles</span>
                </div>
                <div class="cifra">
                    <b>{{ $ahora['en_mantenimiento'] }}</b>
                    <span>fuera de servicio</span>
                </div>
                <div class="cifra">
                    <b>{{ $ahora['reservas_hoy'] }}</b>
                    <span>reservas hoy</span>
                </div>
                <div class="cifra">
                    <b>{{ $ahora['personas_hoy'] }}</b>
                    <span>personas hoy</span>
                </div>
                <div class="cifra">
                    <b>{{ $ahora['pendientes_hoy'] }}</b>
                    <span>por llegar todavía</span>
                </div>
            </div>
        </x-filament::section>

        {{-- ----------------------------------------------------- alertas --}}
        <x-filament::section>
            <x-slot name="heading">Qué necesita atención</x-slot>
            <x-slot name="description">
                Cada línea lleva a donde se resuelve. Si esto está vacío, el laboratorio está al día.
            </x-slot>

            @forelse ($alertas as $alerta)
                <a class="alerta {{ $alerta['tono'] }}" href="{{ $alerta['url'] }}">
                    <b>{{ $alerta['cuantos'] }}</b>
                    <span>
                        <span class="q">{{ $alerta['titulo'] }}</span>
                        <span class="d">{{ $alerta['detalle'] }}</span>
                    </span>
                </a>
            @empty
                <p class="nota" style="margin:0">
                    Nada pendiente: ningún equipo detenido, ningún insumo bajo mínimos, ninguna
                    compra ni encargo esperando. Buen momento para adelantar mantenimiento.
                </p>
            @endforelse
        </x-filament::section>

        {{-- --------------------------------------------------- tendencia --}}
        <x-filament::section>
            <x-slot name="heading">Uso de las últimas {{ $tendencia->count() }} semanas</x-slot>
            <x-slot name="description">
                Horas de uso real —de la llegada a la salida—, no horas reservadas.
            </x-slot>

            {{-- Con todo en cero el gráfico son ocho rayas planas: no dice nada
                 y ocupa media pantalla. Mejor decirlo con palabras. --}}
            @if ($hayUso)
                <div class="barras">
                    @foreach ($tendencia as $semana)
                        <div class="col" title="{{ $semana['sesiones'] }} sesiones · {{ $horas($semana['minutos']) }}">
                            <small>{{ $horas($semana['minutos']) }}</small>
                            <i style="height:{{ max(2, round($semana['minutos'] / $tope * 100)) }}%"></i>
                            <small>{{ $semana['semana'] }}</small>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="nota" style="margin:0">
                    Todavía no hay sesiones cerradas. Las horas aparecen aquí cuando la gente
                    registra su llegada y su salida escaneando el QR de la máquina.
                </p>
            @endif
        </x-filament::section>

        {{-- ---------------------------------------------------- finanzas --}}
        <x-filament::section collapsible>
            <x-slot name="heading">Presupuesto y carga de trabajo</x-slot>

            <div class="rejilla">
                <div class="cifra">
                    <b style="font-size:1.3rem">{{ $pesos($finanzas['presupuesto']) }}</b>
                    <span>presupuesto vigente</span>
                </div>
                <div class="cifra">
                    <b style="font-size:1.3rem">{{ $pesos($finanzas['comprometido']) }}</b>
                    <span>comprometido</span>
                </div>
                <div class="cifra">
                    <b style="font-size:1.3rem">{{ $pesos($finanzas['ejecutado']) }}</b>
                    <span>ejecutado</span>
                </div>
                <div class="cifra">
                    <b style="font-size:1.3rem">{{ $pesos($finanzas['disponible']) }}</b>
                    <span>disponible</span>
                </div>
                <div class="cifra">
                    <b>{{ $finanzas['proyectos'] }} · {{ $finanzas['encargos'] }}</b>
                    <span>proyectos activos · encargos en cola</span>
                </div>
            </div>

            <p class="nota">
                El detalle por periodo, con uso por área y equipo, está en
                <a href="{{ \App\Filament\Pages\Informes::getUrl() }}">Informe de cierre</a>.
            </p>
        </x-filament::section>

    </div>

</x-filament-panels::page>
