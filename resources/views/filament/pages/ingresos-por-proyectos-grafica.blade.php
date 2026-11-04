{{-- Lo facturable mes a mes: lo que pasó, y hacia dónde va en dos escenarios.

     Una sola escala (pesos por mes). Lo real va sólido con puntos; la optimista
     y la realista arrancan del último mes real, punteadas distinto, y llevan su
     nombre al final de la línea: no dependen del color. Los meses bajos
     —intersemestral y fin de año— se marcan con una franja. --}}
@php
    $ajuste ??= \App\Services\Reports\IngresosPorProyectos::ajusteGuardado();
    $real = collect($meses)->map(fn ($m) => ['mes' => $m['mes'], 'v' => $m['cobrado'], 'i' => $m['internos'], 'bajo' => false]);
    $futuro = collect($proyeccion);
    $todos = $real->pluck('mes')->merge($futuro->pluck('mes'))->values();
    $n = max(2, $todos->count());

    $W = 820; $H = 300; $izq = 64; $der = 92; $arriba = 22; $abajo = 34;
    $anchoUtil = $W - $izq - $der; $altoUtil = $H - $arriba - $abajo;

    $maximo = max(1, $real->max('v') ?? 0, $real->max('i') ?? 0, $futuro->max('optimista') ?? 0, $futuro->max('internos') ?? 0);
    $paso = 10 ** floor(log10($maximo));
    foreach ([1, 2, 2.5, 5, 10] as $f) { if ($maximo / ($paso * $f) <= 4) { $paso *= $f; break; } }
    $tope = ceil($maximo / $paso) * $paso;

    $x = fn (int $i) => round($izq + $anchoUtil * $i / ($n - 1), 1);
    $y = fn (int|float $v) => round($arriba + $altoUtil * (1 - $v / $tope), 1);
    $corto = function ($v) {
        if ($v >= 1_000_000) { return '$' . rtrim(rtrim(number_format($v / 1_000_000, 1, ',', '.'), '0'), ',') . ' M'; }
        if ($v >= 1_000) { return '$' . number_format($v / 1_000, 0, ',', '.') . ' mil'; }
        return '$' . number_format($v, 0, ',', '.');
    };
    $pesosLargo = fn ($v) => config('fabos.money.symbol') . number_format((float) $v, 0, ',', '.');
    $nombreMes = fn (string $mes) => \Illuminate\Support\Carbon::parse($mes . '-01')->locale('es')->isoFormat('MMMM YYYY');

    $ultimo = $real->count() - 1;
    // El mes de hoy va por la mitad: su punto va hueco, para no leerlo como una caída.
    $enCurso = now(config('fabos.lab.timezone'))->format('Y-m');
    $puntosReal = $real->values()->map(fn ($p, $i) => [$x($i), $y($p['v'])]);
    $arranque = $ultimo >= 0 ? [[$x($ultimo), $y($real->last()['v'])]] : [];
    $serie = fn (string $clave) => collect($arranque)->merge($futuro->values()->map(fn ($p, $i) => [$x($real->count() + $i), $y($p[$clave])]));
    $linea = fn ($pts) => collect($pts)->map(fn ($p) => $p[0] . ',' . $p[1])->implode(' ');
    $puntosOpt = $serie('optimista');
    $puntosRea = $serie('realista');
    // Los internos: se ven, pero no suman a nada de lo de arriba.
    $internosReal = $real->values()->map(fn ($p, $i) => [$x($i), $y($p['i'])]);
    $internosFuturo = collect($ultimo >= 0 ? [[$x($ultimo), $y($real->last()['i'])]] : [])
        ->merge($futuro->values()->map(fn ($p, $i) => [$x($real->count() + $i), $y($p['internos'])]));
@endphp

<div class="ipp-graf">
    <style>
        .ipp-graf{--g-real:#2a78d6;--g-opt:#eb6834;--g-rea:#1baf7a;--g-int:#8a8984;--g-ink:#0b0b0b;--g-soft:#52514e;--g-rule:rgba(11,11,11,.09);--g-bajo:rgba(11,11,11,.035);--g-surface:#fcfcfb}
        .dark .ipp-graf{--g-real:#3987e5;--g-opt:#d95926;--g-rea:#199e70;--g-int:#8f8e88;--g-ink:#f0efec;--g-soft:#c3c2b7;--g-rule:rgba(240,239,236,.1);--g-bajo:rgba(240,239,236,.05);--g-surface:#1a1a19}
        .ipp-graf .leyenda{display:flex;flex-wrap:wrap;gap:1.1rem;font-size:.8rem;color:var(--g-soft);margin-bottom:.4rem}
        .ipp-graf .leyenda span{display:inline-flex;align-items:center;gap:.4rem}
        .ipp-graf .leyenda svg{flex:none}
        .ipp-graf svg.lienzo{width:100%;height:auto;display:block;overflow:visible}
        .ipp-graf text{font-family:inherit;fill:var(--g-soft);font-size:11px}
        .ipp-graf .hit{fill:transparent;cursor:default}
        .ipp-graf .hit:hover + .marca, .ipp-graf .grupo:hover .marca{r:5.5}
    </style>

    <div class="leyenda" aria-hidden="true">
        <span><svg width="26" height="8"><line x1="1" y1="4" x2="25" y2="4" stroke="var(--g-real)" stroke-width="2" stroke-linecap="round"/><circle cx="13" cy="4" r="3" fill="var(--g-real)"/></svg>Real</span>
        <span><svg width="26" height="8"><line x1="1" y1="4" x2="25" y2="4" stroke="var(--g-opt)" stroke-width="2" stroke-dasharray="6 4" stroke-linecap="round"/></svg>Optimista · ritmo de hoy</span>
        <span><svg width="26" height="8"><line x1="1" y1="4" x2="25" y2="4" stroke="var(--g-rea)" stroke-width="2" stroke-dasharray="2 4" stroke-linecap="round"/></svg>Realista · {{ $ajuste['realista'] }}%</span>
        <span><svg width="26" height="8"><line x1="1" y1="4" x2="25" y2="4" stroke="var(--g-int)" stroke-width="1.25" stroke-dasharray="6 3 1.5 3" stroke-linecap="round"/><rect x="10" y="1" width="6" height="6" fill="var(--g-surface)" stroke="var(--g-int)" stroke-width="1.25"/></svg>Internos · no suman</span>
        <span><svg width="14" height="10"><rect width="14" height="10" rx="2" fill="var(--g-bajo)" stroke="var(--g-rule)"/></svg>Mes bajo</span>
    </div>

    <svg class="lienzo" viewBox="0 0 {{ $W }} {{ $H }}" role="img"
         aria-label="Facturable por mes: lo real y la proyección optimista y realista a doce meses. Los valores están en la tabla de abajo.">
        {{-- Meses bajos, detrás de todo. --}}
        @foreach ($futuro->values() as $i => $p)
            @if ($p['bajo'])
                @php $cx = $x($real->count() + $i); $ancho = $anchoUtil / ($n - 1); @endphp
                <rect x="{{ $cx - $ancho / 2 }}" y="{{ $arriba }}" width="{{ $ancho }}" height="{{ $altoUtil }}" fill="var(--g-bajo)"/>
            @endif
        @endforeach

        {{-- La rejilla, discreta, y su escala. --}}
        @for ($v = 0; $v <= $tope; $v += $paso)
            <line x1="{{ $izq }}" x2="{{ $W - $der }}" y1="{{ $y($v) }}" y2="{{ $y($v) }}" stroke="var(--g-rule)" stroke-width="1"/>
            <text x="{{ $izq - 8 }}" y="{{ $y($v) + 4 }}" text-anchor="end">{{ $corto($v) }}</text>
        @endfor

        {{-- Dónde termina lo real y empieza lo proyectado. --}}
        @if ($ultimo >= 0)
            <line x1="{{ $x($ultimo) }}" x2="{{ $x($ultimo) }}" y1="{{ $arriba }}" y2="{{ $arriba + $altoUtil }}" stroke="var(--g-rule)" stroke-width="1" stroke-dasharray="3 3"/>
            <text x="{{ $x($ultimo) + 5 }}" y="{{ $arriba - 8 }}">proyección →</text>
        @endif

        @foreach ($todos as $i => $mes)
            @php $c = \Illuminate\Support\Carbon::parse($mes . '-01')->locale('es'); @endphp
            <text x="{{ $x($i) }}" y="{{ $H - $abajo + 16 }}" text-anchor="middle">{{ $c->isoFormat('MMM') }}</text>
            @if ($mes === $enCurso && $i <= $ultimo)
                <text x="{{ $x($i) }}" y="{{ $H - $abajo + 29 }}" text-anchor="middle" style="font-size:10px">va</text>
            @elseif ($c->month === 1 || $i === 0)
                <text x="{{ $x($i) }}" y="{{ $H - $abajo + 29 }}" text-anchor="middle" style="font-size:10px">{{ $c->year }}</text>
            @endif
        @endforeach

        {{-- Internos detrás, en gris y más delgados: se miran, no compiten con lo que se factura. --}}
        <polyline points="{{ $linea($internosReal) }}" fill="none" stroke="var(--g-int)" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/>
        <polyline points="{{ $linea($internosFuturo) }}" fill="none" stroke="var(--g-int)" stroke-width="1.25" stroke-dasharray="6 3 1.5 3" stroke-linecap="round" stroke-linejoin="round"/>
        @foreach ($real->values() as $i => $p)
            <rect x="{{ $x($i) - 3 }}" y="{{ $y($p['i']) - 3 }}" width="6" height="6" fill="var(--g-surface)" stroke="var(--g-int)" stroke-width="1.25">
                <title>{{ $nombreMes($p['mes']) }} · internos: {{ $pesosLargo($p['i']) }} (no suman)</title>
            </rect>
        @endforeach

        <polyline points="{{ $linea($puntosRea) }}" fill="none" stroke="var(--g-rea)" stroke-width="2" stroke-dasharray="2 4" stroke-linecap="round" stroke-linejoin="round"/>
        <polyline points="{{ $linea($puntosOpt) }}" fill="none" stroke="var(--g-opt)" stroke-width="2" stroke-dasharray="6 4" stroke-linecap="round" stroke-linejoin="round"/>
        <polyline points="{{ $linea($puntosReal) }}" fill="none" stroke="var(--g-real)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>

        {{-- Los puntos, con su valor al pasar por encima: el blanco es más grande que la marca. --}}
        @foreach ($real->values() as $i => $p)
            <g class="grupo">
                @php $va = $p['mes'] === $enCurso; @endphp
                <circle class="hit" cx="{{ $x($i) }}" cy="{{ $y($p['v']) }}" r="11"><title>{{ $nombreMes($p['mes']) }} · real: {{ $pesosLargo($p['v']) }}{{ $va ? ' (el mes va por la mitad)' : '' }}</title></circle>
                <circle class="marca" cx="{{ $x($i) }}" cy="{{ $y($p['v']) }}" r="4" pointer-events="none"
                        fill="{{ $va ? 'var(--g-surface)' : 'var(--g-real)' }}" stroke="{{ $va ? 'var(--g-real)' : 'var(--g-surface)' }}" stroke-width="2"/>
            </g>
        @endforeach
        @foreach ($futuro->values() as $i => $p)
            @php $cx = $x($real->count() + $i); @endphp
            <g class="grupo">
                <rect class="hit" x="{{ $cx - 10 }}" y="{{ $arriba }}" width="20" height="{{ $altoUtil }}">
                    <title>{{ $p['nombre'] }}{{ $p['bajo'] ? ' (mes bajo)' : '' }}
Optimista: {{ $pesosLargo($p['optimista']) }}
Realista: {{ $pesosLargo($p['realista']) }}
Internos (no suman): {{ $pesosLargo($p['internos']) }}</title>
                </rect>
            </g>
        @endforeach

        {{-- El nombre al final de cada línea. --}}
        @if ($puntosOpt->isNotEmpty())
            @php
                // Las etiquetas del final, de arriba abajo y sin pisarse.
                $etiquetas = collect([
                    ['Optimista', $puntosOpt->last()], ['Realista', $puntosRea->last()], ['Internos', $internosFuturo->last()],
                ])->filter(fn ($e) => $e[1])->sortBy(fn ($e) => $e[1][1])->values();
                $yAnterior = -INF;
            @endphp
            @foreach ($etiquetas as [$texto, $punto])
                @php $ty = max($punto[1] + 4, $yAnterior + 14); $yAnterior = $ty; @endphp
                <text x="{{ $punto[0] + 8 }}" y="{{ $ty }}" style="{{ $texto === 'Internos' ? 'fill:var(--g-soft);font-weight:400' : 'fill:var(--g-ink);font-weight:600' }}">{{ $texto }}</text>
            @endforeach
        @endif
        @if ($ultimo >= 0)
            <text x="{{ $x($ultimo) - 8 }}" y="{{ $y($real->last()['v']) - 10 }}" text-anchor="end" style="fill:var(--g-ink);font-weight:600">Real</text>
        @endif
    </svg>
</div>
