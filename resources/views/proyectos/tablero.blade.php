@extends('layouts.app')
@section('title', $proyecto->code . ' · ' . $proyecto->name)

@php
    use App\Models\Project;
    use App\Models\ProjectTask;

    $tz = config('fabos.lab.timezone');
    $columnas = ProjectTask::ESTADOS;

    // Rango del Gantt. Si no hay tareas con fechas, no se dibuja nada.
    $desde = $cronograma['desde'];
    $hasta = $cronograma['hasta'];
    $totalDias = ($desde && $hasta) ? max(1, (int) $desde->diffInDays($hasta) + 1) : 0;
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

    {{-- ---------------------------------------------------------- costeo --}}
    @php
        $pesos = fn ($v) => config('fabos.money.symbol') . number_format((float) $v, 0, ',', '.');
        $hayCosto = $costeo['total'] > 0 || $costeo['acordado'] > 0;
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
                        <th>Costo total</th>
                        <td style="text-align:right;white-space:nowrap;font-weight:700">
                            {{ $pesos($costeo['total']) }}
                        </td>
                    </tr>
                    <tr>
                        <th style="font-weight:500">Valor acordado</th>
                        <td style="text-align:right;white-space:nowrap">{{ $pesos($costeo['acordado']) }}</td>
                    </tr>
                    <tr>
                        <th>Margen</th>
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
                @if ($costeo['margen'] < 0)
                    Un proyecto que cuesta más de lo que deja no es un fracaso si se sabe:
                    es información para la próxima cotización.
                @endif
            </p>
        </div>
    @endif

    {{-- ---------------------------------------------------------- Kanban --}}
    <h2>Tablero</h2>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(14rem,1fr));gap:.8rem">
        @foreach ($columnas as $clave => $nombre)
            <div class="panel" style="margin:0">
                <h3 style="margin:0 0 .7rem;font-size:.72rem;letter-spacing:.12em;text-transform:uppercase;
                           color:var(--muted);font-family:ui-monospace,Consolas,monospace">
                    {{ $nombre }} · {{ $tablero[$clave]->count() }}
                </h3>

                @forelse ($tablero[$clave] as $tarea)
                    <div style="border:1px solid var(--rule);border-radius:5px;padding:.6rem .7rem;
                                margin-bottom:.5rem;background:var(--ground)">
                        <div style="font-size:.92rem;font-weight:600">
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

                        {{-- Mover de columna sin arrastrar: funciona igual desde
                             un teléfono en el taller que desde el escritorio. --}}
                        <form method="POST" action="{{ route('proyectos.tarea.mover', $tarea) }}"
                              style="margin-top:.5rem;display:flex;gap:.3rem;flex-wrap:wrap">
                            @csrf
                            @foreach ($columnas as $destino => $etiqueta)
                                @continue($destino === $clave)
                                <button type="submit" name="estado" value="{{ $destino }}"
                                        style="margin:0;padding:.2rem .45rem;font-size:.68rem;font-weight:600;
                                               background:transparent;color:var(--muted);
                                               border:1px solid var(--rule);border-radius:3px">
                                    {{ $etiqueta }}
                                </button>
                            @endforeach
                        </form>
                    </div>
                @empty
                    <p class="quien" style="margin:0">—</p>
                @endforelse
            </div>
        @endforeach
    </div>

    {{-- ----------------------------------------------------------- Gantt --}}
    <h2>Cronograma</h2>

    @if ($totalDias === 0)
        <div class="panel">
            <p style="margin:0">Ninguna tarea tiene fechas todavía.</p>
            <p class="help" style="margin:.6rem 0 0">
                Las tareas sin fechas viven solo en el tablero. Al ponerles inicio y fin
                aparecen aquí como barras.
            </p>
        </div>
    @else
        <div class="panel" style="overflow-x:auto">
            <p class="help" style="margin:0 0 1rem">
                Del {{ $desde->format('d/m/Y') }} al {{ $hasta->format('d/m/Y') }}
                · {{ $totalDias }} días
            </p>

            @foreach ($cronograma['tareas'] as $tarea)
                @php
                    // Posición y ancho de la barra, en porcentaje del rango.
                    $offset = (int) $desde->diffInDays($tarea->starts_on);
                    $ancho = $tarea->dias();
                    $izq = round($offset / $totalDias * 100, 2);
                    $largo = max(1.5, round($ancho / $totalDias * 100, 2));
                @endphp

                <div style="display:grid;grid-template-columns:minmax(9rem,14rem) 1fr;gap:.8rem;
                            align-items:center;margin-bottom:.45rem">
                    <div style="font-size:.85rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                        @if ($tarea->is_milestone) ⚑ @endif
                        {{ $tarea->title }}
                    </div>

                    <div style="position:relative;height:1.5rem;background:var(--ground);
                                border-radius:3px;border:1px solid var(--rule)">
                        <div title="{{ $tarea->starts_on->format('d/m/Y') }}{{ $tarea->due_on ? ' — ' . $tarea->due_on->format('d/m/Y') : '' }}"
                             style="position:absolute;top:2px;bottom:2px;
                                    left:{{ $izq }}%;width:{{ $largo }}%;border-radius:3px;
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
@endsection
