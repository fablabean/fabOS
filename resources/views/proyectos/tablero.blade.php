@extends('layouts.app')
@section('title', $proyecto->code . ' · ' . $proyecto->name)

@php
    use App\Models\Project;
    use App\Models\ProjectTask;

    $columnas = ProjectTask::ESTADOS;

    // Rango del Gantt. Si no hay tareas con fechas, no se dibuja nada.
    $desde = $cronograma['desde'];
    $hasta = $cronograma['hasta'];
    $totalDias = ($desde && $hasta) ? max(1, (int) $desde->diffInDays($hasta) + 1) : 0;

    $pesos = fn ($v) => config('fabos.money.symbol') . number_format((float) $v, 0, ',', '.');
@endphp

@section('content')
    <a class="volver" href="/admin/projects">← Volver a proyectos</a>

    <h1 style="margin-top:.6rem">{{ $proyecto->name }}</h1>
    <p class="help">
        <span class="who">{{ $proyecto->code }}</span>
        · {{ $proyecto->quienPide() }}
        @if ($proyecto->lead) · responsable {{ $proyecto->lead->name }} @endif
    </p>

    {{-- El embudo, con la etapa actual marcada. --}}
    <div class="panel">
        <div style="display:flex;flex-wrap:wrap;gap:.4rem;align-items:center">
            @foreach (Project::ETAPAS as $clave => $nombre)
                <span class="pill {{ $clave === $proyecto->stage ? 'ok' : '' }}"
                      style="margin:0;{{ $clave === $proyecto->stage ? '' : 'opacity:.45' }}">
                    {{ $nombre }}
                </span>
                @if (! $loop->last)
                    <span style="color:var(--muted)">→</span>
                @endif
            @endforeach
        </div>

        @if ($falta)
            <p class="help" style="margin:.9rem 0 0">
                <strong>Para avanzar:</strong> {{ $falta }}
            </p>
        @elseif ($siguiente)
            <p class="help" style="margin:.9rem 0 0">
                Puede pasar a <strong>{{ mb_strtolower(Project::ETAPAS[$siguiente]) }}</strong>.
                Se hace desde el listado del backoffice, que registra el cambio.
            </p>
        @endif

        @if ($proyecto->is_internal)
            <p class="help" style="margin:.9rem 0 0">
                <strong>Compromiso interno.</strong> Se costea y se valora igual —ocupa
                máquina, material y gente—, pero no entra dinero por él.
            </p>
        @endif

        <p class="help" style="margin:.6rem 0 0">
            Avance: <strong>{{ $proyecto->avance() }}%</strong>
            · {{ $proyecto->tasks->count() }} tareas
            · {{ $proyecto->documents->count() }} documentos
            @if ($proyecto->due_on)
                · entrega el {{ $proyecto->due_on->format('d/m/Y') }}
            @endif
        </p>
    </div>

    {{-- ------------------------------------------------------- evidencia --}}
    <h2>Etapas y su evidencia</h2>

    <div class="panel">
        <p class="help" style="margin:0 0 1rem">
            Cada etapa deja algo escrito, y ese algo se sostiene solo. Se pueden ir
            llenando en el orden que la realidad imponga —el soporte del contrato a
            veces llega días después de la firma—; lo que no se puede es avanzar sin ellas.
        </p>

        <div class="ev">
            @foreach ($evidencias as $e)
                <div class="fila {{ $e['listo'] ? 'lista' : '' }} {{ $e['actual'] ? 'aqui' : '' }}">
                    <div class="marca">{{ $e['listo'] ? '✓' : '·' }}</div>

                    <div>
                        <div class="titulo">
                            {{ $e['nombre'] }}
                            @if ($e['actual'])
                                <span class="pill ok" style="margin:0 0 0 .4rem">aquí va</span>
                            @endif
                        </div>

                        <div class="que">{{ $e['que'] }}</div>

                        @if ($e['detalle'])
                            <div class="detalle">{{ $e['detalle'] }}</div>
                        @else
                            <div class="detalle falta">
                                Sin evidencia todavía. {{ $e['como'] }}
                            </div>
                        @endif

                        <div class="porque">{{ $e['porque'] }}</div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- ------------------------------------------------------ entregables --}}
    @if ($proyecto->deliverables->isNotEmpty())
        @php
            $cumplidos = $proyecto->deliverables->filter->estaEntregado()->count();
        @endphp

        <h2>Entregables</h2>
        <div class="panel">
            <p class="help" style="margin:0 0 .9rem">
                {{ $cumplidos }} de {{ $proyecto->deliverables->count() }} cumplidos.
                Esto es a lo que se comprometió el laboratorio: al cerrar hay que poder
                decir cuál se entregó y cuál no.
            </p>

            <table>
                <thead>
                    <tr><th></th><th>Entregable</th><th>Para cuándo</th><th>En el tablero</th></tr>
                </thead>
                <tbody>
                @foreach ($proyecto->deliverables as $entregable)
                    <tr>
                        <td style="width:1.5rem;color:{{ $entregable->estaEntregado() ? 'var(--ok)' : 'var(--muted)' }};font-weight:700">
                            {{ $entregable->estaEntregado() ? '✓' : '·' }}
                        </td>
                        <td>
                            {{ $entregable->title }}
                            @if ($entregable->detail)
                                <div class="quien">{{ $entregable->detail }}</div>
                            @endif
                        </td>
                        <td style="white-space:nowrap">
                            {{ $entregable->due_on?->format('d/m/Y') ?? '—' }}
                        </td>
                        <td>
                            @if ($entregable->task)
                                {{ $entregable->estado() }}
                            @else
                                <span class="quien">todavía no es tarea</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            @if ($proyecto->deliverables->whereNull('task_id')->isNotEmpty())
                <p class="foot" style="margin-top:.9rem">
                    Hay entregables que aún no están en el tablero. Se llevan de una vez
                    desde <strong>Tareas → Traer los entregables</strong>, en la ficha del
                    proyecto: se crean como hitos, que es lo que son.
                </p>
            @endif
        </div>
    @endif

    {{-- ------------------------------------------------------- produccion --}}
    @if ($proyecto->producciones->isNotEmpty() || $proyecto->assets->isNotEmpty())
        @php
            $vivas = $proyecto->producciones->whereIn('status', ['confirmada', 'en_curso']);
            $horas = $proyecto->producciones
                ->whereIn('status', ['confirmada', 'en_curso', 'completada'])
                ->sum(fn ($p) => $p->starts_at->diffInMinutes($p->ends_at)) / 60;
        @endphp

        <h2>Máquina</h2>
        <div class="panel">
            @if ($proyecto->assets->isNotEmpty())
                <p class="help" style="margin:0 0 .9rem">
                    Usa {{ $proyecto->assets->pluck('name')->implode(', ') }}.
                    @if ($horas > 0)
                        Lleva <strong>{{ number_format($horas, 1, ',', '.') }} h</strong> de producción.
                    @endif
                </p>
            @endif

            @if ($proyecto->producciones->isNotEmpty())
                <table>
                    <thead>
                        <tr><th>Equipo</th><th>Cuándo</th><th>Dura</th><th>Qué se produce</th><th>Estado</th></tr>
                    </thead>
                    <tbody>
                    @foreach ($proyecto->producciones->sortByDesc('starts_at') as $p)
                        <tr>
                            <td>{{ $p->reservable?->name ?? 'Equipo eliminado' }}</td>
                            <td style="white-space:nowrap">
                                {{ $p->starts_at->timezone(config('fabos.lab.timezone'))->format('d/m/Y H:i') }}
                            </td>
                            <td style="white-space:nowrap">
                                {{ number_format($p->starts_at->diffInMinutes($p->ends_at) / 60, 1, ',', '.') }} h
                            </td>
                            <td>{{ $p->purpose ?? '—' }}</td>
                            <td>{{ \App\Models\Reservation::ESTADOS[$p->status] ?? $p->status }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif

            <p class="foot" style="margin-top:.9rem">
                Producir es reservar: mientras dure, el equipo
                @if ($vivas->isNotEmpty()) <strong>no aparece libre para nadie más</strong>
                @else no aparece libre para nadie más @endif.
                Es la misma tabla y la misma regla que impide que dos reservas choquen —un
                calendario aparte sería un calendario que miente—.
            </p>
        </div>
    @endif

    {{-- ---------------------------------------------------------- costeo --}}
    @php
        $hayCosto = $costeo['total'] > 0 || $costeo['referencia'] > 0;
    @endphp

    @if ($hayCosto)
        <h2>Costeo</h2>
        <div class="panel">
            <table>
                <tbody>
                    <tr>
                        <th style="font-weight:500">
                            Tiempo de máquina
                            <div class="quien">valorado con la tarifa interna; no es plata que salió de caja</div>
                        </th>
                        <td style="text-align:right;white-space:nowrap">{{ $pesos($costeo['maquina']) }}</td>
                    </tr>
                    <tr>
                        <th style="font-weight:500">
                            Material
                            <div class="quien">al costo con que se repone</div>
                        </th>
                        <td style="text-align:right;white-space:nowrap">{{ $pesos($costeo['material']) }}</td>
                    </tr>
                    <tr>
                        <th style="font-weight:500">
                            Compras
                            <div class="quien">lo pedido para este proyecto y ya recibido</div>
                        </th>
                        <td style="text-align:right;white-space:nowrap">{{ $pesos($costeo['compras']) }}</td>
                    </tr>
                    <tr>
                        <th style="font-weight:500">
                            Horas del equipo
                            <div class="quien">{{ $costeo['detalle']['horas']->sum('hours') }} h a tarifa de referencia</div>
                        </th>
                        <td style="text-align:right;white-space:nowrap">{{ $pesos($costeo['gente']) }}</td>
                    </tr>
                    <tr>
                        <th style="font-weight:500">
                            Costos asociados
                            <div class="quien">
                                {{ $costeo['detalle']['asociados']->count() }} anotados: facturas de terceros, fletes, alquileres
                            </div>
                        </th>
                        <td style="text-align:right;white-space:nowrap">{{ $pesos($costeo['asociados']) }}</td>
                    </tr>
                    <tr>
                        <th>Costo total</th>
                        <td style="text-align:right;white-space:nowrap;font-weight:700">
                            {{ $pesos($costeo['total']) }}
                        </td>
                    </tr>
                    @if ($costeo['interno'])
                        <tr>
                            <th style="font-weight:500">
                                Valor del beneficio
                                <div class="quien">en cuánto se valora lo que obtuvo la institución</div>
                            </th>
                            <td style="text-align:right;white-space:nowrap">{{ $pesos($costeo['estimado']) }}</td>
                        </tr>
                    @else
                        <tr>
                            <th style="font-weight:500">
                                Valor estimado
                                <div class="quien">lo que se puso en la propuesta</div>
                            </th>
                            <td style="text-align:right;white-space:nowrap">{{ $pesos($costeo['estimado']) }}</td>
                        </tr>
                        <tr>
                            <th style="font-weight:500">
                                Valor acordado
                                <div class="quien">lo que quedó en el contrato</div>
                            </th>
                            <td style="text-align:right;white-space:nowrap">{{ $pesos($costeo['acordado']) }}</td>
                        </tr>
                    @endif
                    <tr>
                        <th>
                            {{ $costeo['interno'] ? 'Beneficio neto' : 'Margen' }}
                            <div class="quien">
                                @if ($costeo['interno']) valor obtenido menos lo que costó; no es plata que entró
                                @elseif ($costeo['contra'] === 'acordado') contra lo acordado
                                @elseif ($costeo['contra'] === 'estimado') contra lo estimado, que aún no se firma
                                @else sin valor con qué compararlo
                                @endif
                            </div>
                        </th>
                        <td style="text-align:right;white-space:nowrap;font-weight:700;
                                   color:{{ $costeo['margen'] >= 0 ? 'var(--ok)' : 'var(--bad)' }}">
                            {{ $pesos($costeo['margen']) }}
                            @if ($costeo['margen_pct'] !== null)
                                <span class="quien">{{ $costeo['margen_pct'] }}%</span>
                            @endif
                        </td>
                    </tr>
                </tbody>
            </table>

            <p class="foot" style="margin-top:.9rem">
                El material no se cuenta dos veces: la liquidación de cada reserva ya lo cobró,
                así que del tiempo de máquina se descuenta.
                @if ($costeo['estimado'] > 0 && $costeo['acordado'] > 0 && $costeo['acordado'] !== $costeo['estimado'])
                    Entre lo cotizado y lo firmado hay
                    {{ $pesos(abs($costeo['acordado'] - $costeo['estimado'])) }} de diferencia:
                    ese hueco es lo que conviene mirar antes de cotizar el próximo.
                @endif
                @if ($costeo['margen'] < 0)
                    Un proyecto que cuesta más de lo que deja no es un fracaso si se sabe:
                    es información para la próxima cotización.
                @endif
            </p>
        </div>
    @endif

    {{-- ---------------------------------------------------------- Kanban --}}
    <h2>Tablero</h2>

    <div class="kb" id="kb">
        @foreach ($columnas as $clave => $nombre)
            <div class="col panel" data-estado="{{ $clave }}">
                <h3>
                    {{ $nombre }} · <span class="cuenta">{{ $tablero[$clave]->count() }}</span>
                </h3>

                <div class="soltar" data-estado="{{ $clave }}">
                    @forelse ($tablero[$clave] as $tarea)
                        <div class="tarjeta" draggable="true" data-tarea="{{ $tarea->id }}">
                            <div class="t">
                                @if ($tarea->is_milestone) ⚑ @endif
                                {{ $tarea->title }}
                            </div>

                            <div class="quien" style="margin-top:.25rem">
                                {{ $tarea->assignedTo?->name ?? 'sin asignar' }}
                                @if ($tarea->due_on)
                                    · <span style="{{ $tarea->estaVencida() ? 'color:var(--bad);font-weight:600' : '' }}">
                                        {{ $tarea->due_on->format('d/m') }}
                                    </span>
                                @endif
                                @if ($tarea->progress > 0 && $tarea->status !== 'hecha')
                                    · {{ $tarea->progress }}%
                                @endif
                            </div>

                            @if ($tarea->evidence->isNotEmpty())
                                {{-- «Se hizo» es una afirmación; una foto es una
                                     comprobación. En la tarjeta, porque es donde se
                                     mira cuando alguien pregunta cómo va. --}}
                                <div class="pruebas">
                                    @foreach ($tarea->evidence as $prueba)
                                        <a href="{{ $prueba->enlace() }}" target="_blank"
                                           title="{{ $prueba->comoSeLlama() }}">
                                            @if ($prueba->esImagen())
                                                <img src="{{ $prueba->enlace() }}" alt="{{ $prueba->comoSeLlama() }}" loading="lazy">
                                            @else
                                                <span class="chapa">
                                                    {{ $prueba->kind === 'video' ? '▶' : '↗' }}
                                                    {{ $prueba->caption ?: \App\Models\Evidencia::TIPOS[$prueba->kind] }}
                                                </span>
                                            @endif
                                        </a>
                                    @endforeach
                                </div>
                            @endif

                            {{-- Los botones se quedan. Arrastrar no funciona en una
                                 tablet ni con teclado, y el tablero se mira sobre
                                 todo desde el taller. --}}
                            <form method="POST" action="{{ route('proyectos.tarea.mover', $tarea) }}" class="mover">
                                @csrf
                                @foreach ($columnas as $destino => $etiqueta)
                                    @continue($destino === $clave)
                                    <button type="submit" name="estado" value="{{ $destino }}">{{ $etiqueta }}</button>
                                @endforeach
                            </form>
                        </div>
                    @empty
                        <p class="quien vacia">—</p>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>

    <p class="foot" style="margin-top:.6rem">
        Se arrastra de una columna a otra, o se usan los botones de cada tarjeta.
        Lo que se mueva aquí queda guardado al instante.
    </p>

    {{-- ----------------------------------------------------------- Gantt --}}
    <h2>Cronograma</h2>

    @if ($totalDias === 0)
        <div class="panel">
            <p style="margin:0">Ninguna tarea tiene fechas todavía.</p>
            <p class="help" style="margin:.6rem 0 0">
                Las tareas sin fechas viven solo en el tablero. Al ponerles inicio y fin
                aparecen aquí como barras.
            </p>
            <p class="help" style="margin:.6rem 0 0">
                <a href="{{ route('proyectos.cronograma') }}">Ver el cronograma de todos los proyectos →</a>
            </p>
        </div>
    @else
        <div class="panel" style="overflow-x:auto">
            <p class="help" style="margin:0 0 1rem">
                Del {{ $desde->format('d/m/Y') }} al {{ $hasta->format('d/m/Y') }}
                · {{ $totalDias }} días
                · <a href="{{ route('proyectos.cronograma') }}">ver todos los proyectos →</a>
            </p>

            @foreach ($cronograma['tareas'] as $tarea)
                @php
                    // Posición y ancho de la barra, en porcentaje del rango.
                    $offset = (int) $desde->diffInDays($tarea->starts_on);
                    $ancho = $tarea->dias();
                    $izq = round($offset / $totalDias * 100, 2);
                    $largo = max(1.5, round($ancho / $totalDias * 100, 2));
                @endphp

                <div class="gantt-fila">
                    <div class="etiqueta">
                        @if ($tarea->is_milestone) ⚑ @endif
                        {{ $tarea->title }}
                    </div>

                    <div class="pista">
                        <div class="barra"
                             title="{{ $tarea->starts_on->format('d/m/Y') }}{{ $tarea->due_on ? ' — ' . $tarea->due_on->format('d/m/Y') : '' }}"
                             style="left:{{ $izq }}%;width:{{ $largo }}%;
                                    background:{{ $tarea->status === 'hecha' ? 'var(--ok)' : ($tarea->estaVencida() ? 'var(--bad)' : 'var(--accent)') }};
                                    opacity:{{ $tarea->status === 'hecha' ? '.55' : '.9' }}">
                        </div>
                    </div>
                </div>
            @endforeach

            <p class="foot" style="margin-top:1rem">
                Las barras salen de las mismas tareas del tablero. Verde: hecha.
                Rojo: pasó su fecha y sigue abierta.
            </p>
        </div>
    @endif

    {{-- ---------------------------------------------------------- semana --}}
    @php
        $s = $semana;
        $hPx = 2.6; // rem por hora
        $alto = ($s['horaHasta'] - $s['horaDesde']) * $hPx;
        $filtros = array_filter([
            'espacio' => $s['espacio'],
            'solo'    => $s['solo'] ? 1 : null,
            'vista'   => $s['vista'] === 'espacios' ? 'espacios' : null,
        ]);
        $url = fn (array $cambios) => route('proyectos.tablero', $proyecto) . '?'
            . http_build_query(array_filter($cambios + $filtros + ['semana' => $s['desde']->toDateString()])) . '#semana';
        $irA = fn ($dia) => $url(['semana' => $dia->toDateString()]);
        $tz = config('fabos.lab.timezone');
        $hoy = now($tz)->toDateString();
        $nombresDia = ['lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'];
        $total = collect($s['bloques'])->flatten(1);

        $clasesDe = fn (array $b) => collect([
            $b['delProyecto'] ? 'nuestro' : '',
            $b['estado'] === 'solicitada' ? 'pedida' : '',
            in_array($b['estado'], ['completada', 'no_show']) ? 'pasada' : '',
        ])->filter()->implode(' ');
        $quienDe = fn (array $b) => $b['responsables'] ? implode(', ', $b['responsables']) : ($b['reserva'] ?? 'Sin responsable');
        $tituloDe = fn (array $b) => $b['hora'] . ' · ' . $b['que']
            . ($b['responsables'] ? "\nResponde: " . implode(', ', $b['responsables']) : '')
            . ($b['reserva'] ? "\nReservó: " . $b['reserva'] : '')
            . ($b['para'] ? "\nPara: " . $b['para'] : '')
            . "\n" . $b['estadoTxt'];
    @endphp

    <h2 id="semana">Semana del laboratorio</h2>

    <div class="panel">
        <div class="sem-barra">
            <div class="sem-nav">
                <a href="{{ $irA($s['desde']->copy()->subWeek()) }}">← Anterior</a>
                <strong>{{ $s['desde']->format('d/m') }} – {{ $s['hasta']->format('d/m/Y') }}</strong>
                <a href="{{ $irA($s['desde']->copy()->addWeek()) }}">Siguiente →</a>
                @unless ($s['desde']->toDateString() === now($tz)->startOfWeek()->toDateString())
                    <a href="{{ $irA(now($tz)) }}">Esta semana</a>
                @endunless
            </div>

            <div class="sem-vista" role="group" aria-label="Cómo ver la semana">
                <a href="{{ $url(['vista' => null]) }}" class="{{ $s['vista'] === 'horas' ? 'activa' : '' }}">Por horas</a>
                <a href="{{ $url(['vista' => 'espacios']) }}" class="{{ $s['vista'] === 'espacios' ? 'activa' : '' }}">Por espacios</a>
            </div>

            <form method="get" action="{{ route('proyectos.tablero', $proyecto) }}#semana" class="sem-filtro">
                <input type="hidden" name="semana" value="{{ $s['desde']->toDateString() }}">
                @if ($s['vista'] === 'espacios') <input type="hidden" name="vista" value="espacios"> @endif
                <select name="espacio" onchange="this.form.submit()" aria-label="Espacio">
                    <option value="">Todos los espacios</option>
                    @foreach ($s['espacios'] as $esp)
                        <option value="{{ $esp->id }}" @selected($s['espacio'] === $esp->id)>{{ $esp->name }}</option>
                    @endforeach
                </select>
                <label class="sem-check">
                    <input type="checkbox" name="solo" value="1" @checked($s['solo']) onchange="this.form.submit()">
                    Solo este proyecto
                </label>
                <noscript><button class="secundario" style="margin:0">Ver</button></noscript>
            </form>
        </div>

        @if ($total->isEmpty())
            <p class="help" style="margin:0">
                Nada reservado esta semana{{ $s['espacio'] ? ' en este espacio' : '' }}{{ $s['solo'] ? ' para este proyecto' : '' }}.
            </p>
        @elseif ($s['vista'] === 'espacios')
            {{-- Una fila por espacio: lo que se pregunta es «¿cuándo está libre
                 la sala de corte?», y eso se lee de corrido en su fila. --}}
            <div class="sem-scroll">
                <table class="sem-tabla">
                    <thead>
                        <tr>
                            <th></th>
                            @foreach ($s['dias'] as $dia)
                                <th class="{{ $dia->toDateString() === $hoy ? 'hoy' : '' }}">
                                    {{ $nombresDia[$dia->dayOfWeekIso - 1] }} <span>{{ $dia->format('d') }}</span>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ($total->pluck('espacio')->unique()->sort() as $espacio)
                        <tr>
                            <th scope="row">{{ $espacio }}</th>
                            @foreach ($s['dias'] as $dia)
                                <td class="{{ $dia->toDateString() === $hoy ? 'hoy' : '' }}">
                                    @foreach (collect($s['bloques'][$dia->toDateString()] ?? [])->where('espacio', $espacio)->sortBy('desde') as $b)
                                        <div class="sem-f {{ $clasesDe($b) }}" title="{{ $tituloDe($b) }}">
                                            <span class="h">{{ $b['hora'] }}</span>
                                            {{-- La sala ya la dice la fila; se nombra lo que hay dentro. --}}
                                            @if ($b['tipo'] !== 'espacio')
                                                <span class="q">{{ $b['recurso'] ?? $b['que'] }}</span>
                                            @endif
                                            <span class="r">{{ $quienDe($b) }}</span>
                                        </div>
                                    @endforeach
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @else
            @php
                // Cada día se ensancha con lo que se le cruza: siete impresoras
                // produciendo a la vez no caben en una columna de seis letras.
                $columnas = collect($s['dias'])->map(function ($dia) use ($s) {
                    $carriles = collect($s['bloques'][$dia->toDateString()] ?? [])->max('carriles') ?? 1;
                    return 'minmax(' . max(6.5, $carriles * 5.2) . 'rem,' . $carriles . 'fr)';
                })->implode(' ');
            @endphp
            <div class="sem-scroll">
                <div class="sem" style="grid-template-columns:3rem {{ $columnas }}">
                    <div></div>
                    @foreach ($s['dias'] as $dia)
                        <div class="sem-dia {{ $dia->toDateString() === $hoy ? 'hoy' : '' }}">
                            {{ $nombresDia[$dia->dayOfWeekIso - 1] }} <span>{{ $dia->format('d') }}</span>
                        </div>
                    @endforeach

                    <div class="sem-horas" style="height:{{ $alto }}rem">
                        @for ($h = $s['horaDesde']; $h < $s['horaHasta']; $h++)
                            <div style="top:{{ ($h - $s['horaDesde']) * $hPx }}rem">{{ sprintf('%02d', $h) }}:00</div>
                        @endfor
                    </div>

                    @foreach ($s['dias'] as $dia)
                        <div class="sem-col {{ $dia->toDateString() === $hoy ? 'hoy' : '' }}"
                             style="height:{{ $alto }}rem;background-size:100% {{ $hPx }}rem">
                            @foreach ($s['bloques'][$dia->toDateString()] ?? [] as $b)
                                @php
                                    $top = ($b['desde'] / 60 - $s['horaDesde']) * $hPx;
                                    $altoB = max(1.1, ($b['hasta'] - $b['desde']) / 60 * $hPx);
                                    $ancho = 100 / $b['carriles'];
                                @endphp
                                <div class="sem-b {{ $clasesDe($b) }}" title="{{ $tituloDe($b) }}"
                                     style="top:{{ $top }}rem;height:{{ $altoB }}rem;--alto:{{ $altoB }}rem;
                                            left:calc({{ $b['carril'] * $ancho }}% + 1px);width:calc({{ $ancho }}% - 2px)">
                                    <div class="h">{{ $b['hora'] }}</div>
                                    <div class="q">{{ $b['que'] }}</div>
                                    <div class="r">{{ $quienDe($b) }}</div>
                                    @if ($b['para'])<div class="p">{{ $b['para'] }}</div>@endif
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="sem-ley">
            <span><i class="sem-m nuestro"></i> De este proyecto</span>
            <span><i class="sem-m"></i> De otros</span>
            <span><i class="sem-m pedida"></i> Solicitada, sin confirmar</span>
            <span><i class="sem-m pasada"></i> Ya pasó</span>
        </div>
        <p class="foot" style="margin-top:.5rem">
            Con cada franja va quién responde: quien asesora o acompaña; si nadie del equipo
            está asignado, quien reservó. Pase el cursor por una franja para ver el detalle.
        </p>
    </div>

    {{-- ------------------------------------------------------ documentos --}}
    @if ($proyecto->documents->isNotEmpty())
        <h2>Documentos</h2>
        <div class="panel">
            <table>
                <thead><tr><th>Tipo</th><th>Documento</th><th>Firmado</th></tr></thead>
                <tbody>
                @foreach ($proyecto->documents as $doc)
                    <tr>
                        <td>{{ \App\Models\ProjectDocument::TIPOS[$doc->kind] ?? $doc->kind }}</td>
                        <td>
                            @if ($doc->enlace())
                                <a href="{{ $doc->enlace() }}" target="_blank">{{ $doc->title }}</a>
                            @else
                                {{ $doc->title }}
                            @endif
                        </td>
                        <td>{{ $doc->signed_on?->format('d/m/Y') ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- ------------------------------------------------- costos asociados --}}
    @if ($costeo['detalle']['asociados']->isNotEmpty())
        <h2>Costos asociados</h2>
        <div class="panel">
            <table>
                <thead><tr><th>Cuándo</th><th>Concepto</th><th>A quién</th><th style="text-align:right">Monto</th></tr></thead>
                <tbody>
                @foreach ($costeo['detalle']['asociados'] as $costo)
                    <tr>
                        <td>{{ $costo->incurred_on?->format('d/m/Y') ?? '—' }}</td>
                        <td>
                            {{ $costo->concept }}
                            <div class="quien">
                                {{ \App\Models\ProjectCost::TIPOS[$costo->kind] ?? $costo->kind }}
                                @if ($costo->document_ref) · {{ $costo->document_ref }} @endif
                            </div>
                        </td>
                        <td>{{ $costo->supplier ?? '—' }}</td>
                        <td style="text-align:right;white-space:nowrap">{{ $pesos($costo->amount) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- ---------------------------------------------------------- equipo --}}
    @if ($proyecto->members->isNotEmpty())
        <h2>Equipo</h2>
        <div class="panel">
            <table>
                <thead><tr><th>Quién</th><th>Papel</th><th>Qué hace</th></tr></thead>
                <tbody>
                @foreach ($proyecto->members as $miembro)
                    <tr>
                        <td>
                            {{ $miembro->nombre() }}
                            @if ($miembro->organization)
                                <div class="quien">{{ $miembro->organization }}</div>
                            @endif
                        </td>
                        <td>{{ \App\Models\ProjectMember::ROLES[$miembro->role] ?? $miembro->role }}</td>
                        <td>{{ $miembro->note }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Rejillas propias: el CSS de Filament no llega hasta aquí, y las
         utilidades responsivas de Tailwind no están compiladas. --}}
    <style>
        .ev .fila { display:grid; grid-template-columns:1.6rem 1fr; gap:.7rem;
                    padding:.75rem 0; border-top:1px solid var(--rule); }
        .ev .fila:first-child { border-top:0; padding-top:0; }
        .ev .marca { font-weight:700; color:var(--muted); text-align:center; }
        .ev .fila.lista .marca { color:var(--ok); }
        .ev .fila.aqui { background:color-mix(in srgb, var(--accent) 6%, transparent);
                         border-radius:5px; padding-left:.5rem; padding-right:.5rem; }
        .ev .titulo { font-weight:600; }
        .ev .que { font-size:.88rem; color:var(--ink-soft); }
        .ev .detalle { font-size:.85rem; margin-top:.25rem; }
        .ev .detalle.falta { color:var(--muted); font-style:italic; }
        .ev .porque { font-size:.78rem; color:var(--muted); margin-top:.3rem; }

        .kb { display:grid; grid-template-columns:repeat(auto-fit,minmax(14rem,1fr)); gap:.8rem; }
        .kb .col { margin:0; }
        .kb h3 { margin:0 0 .7rem; font-size:.72rem; letter-spacing:.12em; text-transform:uppercase;
                 color:var(--muted); font-family:ui-monospace,Consolas,monospace; }
        .kb .soltar { min-height:3rem; border-radius:5px; transition:background .12s; }
        .kb .soltar.encima { background:color-mix(in srgb, var(--accent) 12%, transparent);
                             outline:2px dashed var(--accent); outline-offset:2px; }
        .kb .tarjeta { border:1px solid var(--rule); border-radius:5px; padding:.6rem .7rem;
                       margin-bottom:.5rem; background:var(--ground); cursor:grab; }
        .kb .tarjeta:active { cursor:grabbing; }
        .kb .tarjeta.viajando { opacity:.4; }
        .kb .tarjeta .t { font-size:.92rem; font-weight:600; }
        .kb .pruebas { margin-top:.45rem; display:flex; gap:.3rem; flex-wrap:wrap; align-items:center; }
        .kb .pruebas img { width:3rem; height:3rem; object-fit:cover; border-radius:4px;
                           border:1px solid var(--rule); display:block; }
        .kb .pruebas .chapa { font-size:.68rem; font-weight:600; color:var(--muted);
                              border:1px solid var(--rule); border-radius:3px;
                              padding:.15rem .35rem; display:inline-block; }
        .kb .mover { margin-top:.5rem; display:flex; gap:.3rem; flex-wrap:wrap; }
        .kb .mover button { margin:0; padding:.2rem .45rem; font-size:.68rem; font-weight:600;
                            background:transparent; color:var(--muted);
                            border:1px solid var(--rule); border-radius:3px; }
        .kb .vacia { margin:0; }

        .gantt-fila { display:grid; grid-template-columns:minmax(9rem,14rem) 1fr; gap:.8rem;
                      align-items:center; margin-bottom:.45rem; }
        .gantt-fila .etiqueta { font-size:.85rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .gantt-fila .pista { position:relative; height:1.5rem; background:var(--ground);
                             border-radius:3px; border:1px solid var(--rule); }
        .gantt-fila .barra { position:absolute; top:2px; bottom:2px; border-radius:3px; }

        .sem-barra { display:flex; flex-wrap:wrap; gap:.8rem 1.2rem; align-items:center;
                     justify-content:space-between; margin-bottom:1rem; }
        .sem-nav { display:flex; flex-wrap:wrap; gap:.4rem 1rem; align-items:center; font-size:.9rem; }
        .sem-vista { display:inline-flex; border:1px solid var(--rule); border-radius:4px; overflow:hidden; font-size:.85rem; }
        .sem-vista a { padding:.35rem .75rem; text-decoration:none; color:var(--ink-soft); }
        .sem-vista a.activa { background:var(--accent); color:var(--surface); font-weight:600; }
        .sem-tabla { width:100%; min-width:44rem; border-collapse:separate; border-spacing:2px; table-layout:fixed; }
        .sem-tabla th, .sem-tabla td { vertical-align:top; padding:.3rem; border:0; }
        .sem-tabla thead th { font-size:.72rem; letter-spacing:.1em; text-transform:uppercase; color:var(--muted);
                              font-family:ui-monospace,Consolas,monospace; font-weight:400; text-align:left; }
        .sem-tabla thead th span { font-size:.95rem; color:var(--ink); letter-spacing:0; }
        .sem-tabla thead th.hoy, .sem-tabla thead th.hoy span { color:var(--accent); font-weight:700; }
        .sem-tabla thead th:first-child, .sem-tabla tbody th { width:9rem; }
        .sem-tabla tbody th { font-size:.82rem; text-align:left; font-weight:600; font-family:inherit;
                             text-transform:none; letter-spacing:0; color:var(--ink); }
        .sem-tabla td { background:var(--ground); border-radius:3px; }
        .sem-tabla td.hoy { outline:2px solid color-mix(in srgb, var(--accent) 45%, transparent); }
        .sem-f { font-size:.7rem; line-height:1.25; padding:.2rem .35rem; margin-bottom:.25rem; border-radius:3px;
                 background:color-mix(in srgb, var(--muted) 22%, var(--surface)); border-left:3px solid var(--muted); }
        .sem-f span { display:block; }
        .sem-f .h { font-family:ui-monospace,Consolas,monospace; font-size:.62rem; color:var(--ink-soft); }
        .sem-f .q { font-weight:600; }
        .sem-f .r { color:var(--ink-soft); }
        .sem-f.nuestro { background:color-mix(in srgb, var(--accent) 26%, var(--surface)); border-left-color:var(--accent); }
        .sem-f.pedida { background:repeating-linear-gradient(135deg, transparent 0 5px,
                            color-mix(in srgb, var(--warn) 14%, transparent) 5px 10px), var(--surface);
                        border-left:3px dashed var(--warn); }
        .sem-f.nuestro.pedida { border-left-color:var(--accent); }
        .sem-f.pasada { opacity:.5; }
        .sem-filtro { display:flex; flex-wrap:wrap; gap:.6rem 1rem; align-items:center; }
        .sem-filtro select { width:auto; min-width:12rem; padding:.4rem .6rem; font-size:.88rem; }
        .sem-check { display:flex; gap:.4rem; align-items:center; margin:0; font-family:inherit;
                     font-size:.85rem; letter-spacing:0; text-transform:none; color:var(--ink-soft); }
        .sem-scroll { overflow-x:auto; }
        .sem { display:grid; min-width:40rem; column-gap:2px; }
        .sem-dia { font-size:.72rem; letter-spacing:.1em; text-transform:uppercase; color:var(--muted);
                   font-family:ui-monospace,Consolas,monospace; padding:0 0 .4rem .3rem; }
        .sem-dia span { font-size:.95rem; color:var(--ink); letter-spacing:0; }
        .sem-dia.hoy, .sem-dia.hoy span { color:var(--accent); font-weight:700; }
        .sem-horas { position:relative; }
        .sem-horas div { position:absolute; right:.4rem; transform:translateY(-.45rem);
                         font-size:.66rem; color:var(--muted); font-family:ui-monospace,Consolas,monospace; }
        .sem-col { position:relative; background:var(--ground); border-radius:3px;
                   background-image:linear-gradient(to bottom, var(--rule) 1px, transparent 1px); }
        .sem-col.hoy { outline:2px solid color-mix(in srgb, var(--accent) 45%, transparent); }
        .sem-b { position:absolute; overflow:hidden; border-radius:3px; padding:.15rem .3rem;
                 font-size:.68rem; line-height:1.2; cursor:default;
                 background:color-mix(in srgb, var(--muted) 22%, var(--surface));
                 border-left:3px solid var(--muted); color:var(--ink); }
        .sem-b.nuestro { background:color-mix(in srgb, var(--accent) 26%, var(--surface));
                         border-left-color:var(--accent); }
        .sem-b.pedida { background:repeating-linear-gradient(135deg, transparent 0 5px,
                            color-mix(in srgb, var(--warn) 14%, transparent) 5px 10px), var(--surface);
                        border-left:3px dashed var(--warn); }
        .sem-b.nuestro.pedida { border-left-color:var(--accent); }
        .sem-b.pasada { opacity:.5; }
        /* Al pasar por encima, el bloque crece hasta que se lea entero. */
        .sem-b:hover { z-index:2; height:auto !important; min-height:var(--alto); box-shadow:0 2px 8px rgb(0 0 0 / .25); }
        .sem-b .h { font-family:ui-monospace,Consolas,monospace; font-size:.62rem; color:var(--ink-soft); }
        .sem-b .q { font-weight:600; }
        .sem-b .r { color:var(--ink-soft); }
        .sem-b .p { color:var(--muted); font-style:italic; }
        .sem-ley { display:flex; flex-wrap:wrap; gap:.4rem 1.2rem; margin-top:.9rem; font-size:.8rem; color:var(--ink-soft); }
        .sem-ley span { display:inline-flex; gap:.35rem; align-items:center; white-space:nowrap; }
        .sem-m { display:inline-block; width:.9rem; height:.9rem; border-radius:2px; vertical-align:middle;
                 background:color-mix(in srgb, var(--muted) 22%, var(--surface)); border-left:3px solid var(--muted); }
        .sem-m.nuestro { background:color-mix(in srgb, var(--accent) 26%, var(--surface)); border-left-color:var(--accent); }
        .sem-m.pedida { background:color-mix(in srgb, var(--warn) 14%, var(--surface)); border-left:3px dashed var(--warn); }
        .sem-m.pasada { opacity:.5; }
    </style>

    <script>
        // Arrastrar y soltar como en un tablero de verdad. Los botones de cada
        // tarjeta siguen ahí: esto no funciona con el dedo ni con teclado, y el
        // tablero se mira sobre todo desde una tablet en el taller.
        (function () {
            const tablero = document.getElementById('kb');
            if (!tablero) return;

            let viajando = null;

            tablero.querySelectorAll('.tarjeta').forEach(function (tarjeta) {
                tarjeta.addEventListener('dragstart', function (e) {
                    viajando = tarjeta;
                    tarjeta.classList.add('viajando');
                    e.dataTransfer.effectAllowed = 'move';
                    // Firefox no arranca el arrastre sin algo en el portapapeles.
                    e.dataTransfer.setData('text/plain', tarjeta.dataset.tarea);
                });

                tarjeta.addEventListener('dragend', function () {
                    tarjeta.classList.remove('viajando');
                    viajando = null;
                });
            });

            tablero.querySelectorAll('.soltar').forEach(function (zona) {
                zona.addEventListener('dragover', function (e) {
                    if (!viajando) return;
                    e.preventDefault();
                    zona.classList.add('encima');
                });

                zona.addEventListener('dragleave', function () {
                    zona.classList.remove('encima');
                });

                zona.addEventListener('drop', function (e) {
                    e.preventDefault();
                    zona.classList.remove('encima');
                    if (!viajando) return;

                    const tarjeta = viajando;
                    const origen = tarjeta.closest('.soltar');
                    if (origen === zona) return;

                    // Se mueve primero y se guarda después: el tablero responde
                    // al instante, y si el guardado falla se recarga y manda la
                    // base de datos, no la pantalla.
                    zona.appendChild(tarjeta);
                    zona.querySelector('.vacia')?.remove();
                    recontar();
                    rehacerBotones(tarjeta, zona.dataset.estado);

                    // La URL y el token salen del formulario de la propia
                    // tarjeta: ya están ahí, y así no hay una segunda forma de
                    // construirlos que pueda quedarse atrás.
                    const form = tarjeta.querySelector('.mover');

                    fetch(form.action, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': form.querySelector('input[name="_token"]').value,
                            'X-Requested-With': 'XMLHttpRequest',
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'estado=' + encodeURIComponent(zona.dataset.estado),
                    }).then(function (r) {
                        if (!r.ok) window.location.reload();
                    }).catch(function () {
                        window.location.reload();
                    });
                });
            });

            function recontar() {
                tablero.querySelectorAll('.col').forEach(function (col) {
                    const n = col.querySelectorAll('.tarjeta').length;
                    col.querySelector('.cuenta').textContent = n;

                    const zona = col.querySelector('.soltar');
                    if (n === 0 && !zona.querySelector('.vacia')) {
                        const p = document.createElement('p');
                        p.className = 'quien vacia';
                        p.textContent = '—';
                        zona.appendChild(p);
                    }
                });
            }

            // Los botones de la tarjeta ofrecen las OTRAS columnas: al cambiarla
            // de sitio hay que rehacerlos, o quedaría ofreciendo la suya.
            function rehacerBotones(tarjeta, estado) {
                const form = tarjeta.querySelector('.mover');
                if (!form) return;

                form.querySelectorAll('button').forEach(function (b) {
                    b.hidden = b.value === estado;
                });
            }
        })();
    </script>
@endsection
