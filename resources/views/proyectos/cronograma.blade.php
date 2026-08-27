@extends('layouts.app')
@section('title', 'Cronograma de proyectos · ' . config('fabos.lab.name'))
{{-- Un año de barras no cabe en la columna de lectura: estrujarlo ahi obliga a
     desplazarse en horizontal para ver de septiembre en adelante. --}}
@section('ancho', 'completo')

@php
    use App\Models\Project;

    $tz = config('fabos.lab.timezone');
    $hoy = now($tz)->startOfDay();

    // El rango se estira hasta hoy: un proyecto que terminó en marzo no debería
    // dejar el cronograma anclado en marzo mientras el laboratorio sigue en agosto.
    $inicio = $desde ? $desde->copy()->startOfDay() : null;
    $fin = $hasta ? $hasta->copy()->startOfDay() : null;

    if ($inicio && $fin) {
        if ($hoy->lt($inicio)) $inicio = $hoy->copy();
        if ($hoy->gt($fin)) $fin = $hoy->copy();
    }

    $totalDias = ($inicio && $fin) ? max(1, (int) $inicio->diffInDays($fin) + 1) : 0;

    // Marcas de mes, para que las barras se puedan leer contra el calendario.
    $meses = [];
    if ($totalDias > 0) {
        $cursor = $inicio->copy()->startOfMonth();
        while ($cursor->lte($fin)) {
            $arranque = $cursor->lt($inicio) ? $inicio : $cursor;
            $meses[] = [
                'nombre' => $arranque->translatedFormat('M Y'),
                'izq'    => round((int) $inicio->diffInDays($arranque) / $totalDias * 100, 3),
            ];
            $cursor->addMonth();
        }
    }

    $hoyPct = $totalDias > 0 ? round((int) $inicio->diffInDays($hoy) / $totalDias * 100, 3) : null;

    $colorDe = function (Project $p) use ($hoy) {
        if ($p->estaCerrado() || $p->status === 'cerrado') return 'var(--ok)';
        if ($p->due_on && $p->due_on->lt($hoy)) return 'var(--bad)';
        return 'var(--accent)';
    };
@endphp

@section('content')
    <a class="volver" href="/admin/projects">← Volver a proyectos</a>

    <h1 style="margin-top:.6rem">Cronograma de proyectos</h1>
    <p class="help">
        El Gantt de un proyecto responde «¿vamos a tiempo?». Este responde la otra
        pregunta, la que decide si se acepta el siguiente encargo:
        <strong>¿qué se nos junta?</strong> Vistos por separado todos parecen holgados.
    </p>

    <div class="panel">
        <p class="help" style="margin:0">
            {{ $conFechas->count() }} {{ $conFechas->count() === 1 ? 'proyecto' : 'proyectos' }} con fechas
            @if ($totalDias > 0)
                · del {{ $inicio->format('d/m/Y') }} al {{ $fin->format('d/m/Y') }}
            @endif
            ·
            @if ($todos)
                <a href="{{ route('proyectos.cronograma') }}">ver solo los activos</a>
            @else
                <a href="{{ route('proyectos.cronograma', ['todos' => 1]) }}">incluir los cerrados y descartados</a>
            @endif
        </p>
    </div>

    @if ($totalDias === 0)
        <div class="panel">
            <p style="margin:0">Ningún proyecto tiene fechas todavía.</p>
            <p class="help" style="margin:.6rem 0 0">
                Las fechas de arranque y entrega se ponen en la ficha de cada proyecto.
                Sin ellas no hay con qué dibujar el calendario.
            </p>
        </div>
    @else
        <div class="panel" style="overflow-x:auto">
            <div class="gg">
                {{-- Regla de meses --}}
                <div class="fila regla">
                    <div class="etiqueta"></div>
                    <div class="pista">
                        @foreach ($meses as $mes)
                            <div class="mes" style="left:{{ $mes['izq'] }}%">{{ $mes['nombre'] }}</div>
                        @endforeach
                    </div>
                </div>

                @foreach ($conFechas as $p)
                    @php
                        $arranca = ($p->starts_on ?? $p->due_on)->copy()->startOfDay();
                        $entrega = ($p->due_on ?? $p->starts_on)->copy()->startOfDay();

                        if ($entrega->lt($arranca)) $entrega = $arranca->copy();

                        $izq = round((int) $inicio->diffInDays($arranca) / $totalDias * 100, 3);
                        $largo = max(1.2, round(((int) $arranca->diffInDays($entrega) + 1) / $totalDias * 100, 3));
                    @endphp

                    <div class="fila">
                        <div class="etiqueta">
                            <a href="{{ route('proyectos.tablero', $p) }}">{{ $p->name }}</a>
                            <div class="quien">
                                {{ $p->code }} · {{ Project::ETAPAS[$p->stage] ?? $p->stage }}
                                @if ($p->lead) · {{ $p->lead->name }} @endif
                            </div>
                        </div>

                        <div class="pista">
                            @if ($hoyPct !== null && $hoyPct >= 0 && $hoyPct <= 100)
                                <div class="hoy" style="left:{{ $hoyPct }}%"></div>
                            @endif

                            <div class="barra"
                                 title="{{ $p->starts_on?->format('d/m/Y') ?? 'sin arranque' }} — {{ $p->due_on?->format('d/m/Y') ?? 'sin entrega' }}"
                                 style="left:{{ $izq }}%;width:{{ $largo }}%;background:{{ $colorDe($p) }}">
                                <span>{{ $p->avance() }}%</span>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <p class="foot" style="margin-top:1rem">
                La línea vertical es hoy. Verde: cerrado. Rojo: pasó su fecha de entrega
                y sigue abierto. Un proyecto sin fecha de arranque se dibuja sobre su
                fecha de entrega, y al revés.
            </p>
        </div>
    @endif

    @if ($sinFechas->isNotEmpty())
        <h2>Sin fechas</h2>
        <div class="panel">
            <p class="help" style="margin:0 0 .8rem">
                Estos no aparecen arriba. No es un olvido de la pantalla: es que nadie
                se ha comprometido todavía con una fecha.
            </p>
            <table>
                <thead><tr><th>Código</th><th>Proyecto</th><th>Etapa</th><th>Responsable</th></tr></thead>
                <tbody>
                @foreach ($sinFechas as $p)
                    <tr>
                        <td class="quien">{{ $p->code }}</td>
                        <td><a href="{{ route('proyectos.tablero', $p) }}">{{ $p->name }}</a></td>
                        <td>{{ Project::ETAPAS[$p->stage] ?? $p->stage }}</td>
                        <td>{{ $p->lead?->name ?? 'sin asignar' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Rejilla propia: las utilidades responsivas de Tailwind no están compiladas. --}}
    <style>
        .gg { min-width:44rem; }
        .gg .fila { display:grid; grid-template-columns:minmax(11rem,18rem) 1fr; gap:.8rem;
                    align-items:center; margin-bottom:.5rem; }
        .gg .etiqueta { font-size:.88rem; }
        .gg .etiqueta a { text-decoration:none; }
        .gg .pista { position:relative; height:1.7rem; background:var(--ground);
                     border-radius:3px; border:1px solid var(--rule); }
        .gg .barra { position:absolute; top:2px; bottom:2px; border-radius:3px;
                     display:flex; align-items:center; justify-content:center; overflow:hidden; }
        .gg .barra span { font-size:.66rem; font-weight:700; color:var(--surface);
                          font-family:ui-monospace,Consolas,monospace; }
        .gg .hoy { position:absolute; top:-2px; bottom:-2px; width:2px;
                   background:var(--warn); opacity:.85; }
        .gg .regla .pista { background:transparent; border:0; height:1.2rem; }
        .gg .mes { position:absolute; top:0; font-size:.66rem; color:var(--muted);
                   font-family:ui-monospace,Consolas,monospace; border-left:1px solid var(--rule);
                   padding-left:.25rem; height:1.2rem; white-space:nowrap; }
    </style>
@endsection
