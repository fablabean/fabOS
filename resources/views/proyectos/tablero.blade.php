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
                    <tr>
                        <th>
                            Margen
                            <div class="quien">
                                @if ($costeo['contra'] === 'acordado') contra lo acordado
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
