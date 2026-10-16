@php
    /** @var \App\Models\Pagina $pagina */
    $lab = config('fabos.lab.name');
    $institucion = config('fabos.lab.institution');
    $ciudad = config('fabos.lab.city');
    $acento = '#0D6E63';
    $n = fn ($v) => number_format((float) $v, 0, ',', '.');
    $pct = fn ($v) => number_format((float) $v, 1, ',', '.') . ' %';
    $nombreDeCanal = \App\Services\Analitica\MetricasDePagina::CANALES;
    $nombreDeDispositivo = ['movil' => 'Celular', 'tableta' => 'Tableta', 'escritorio' => 'Computador'];
    $maxDia = max(1, collect($porDia)->max('personas'));
    $logo = $logo ?? null;
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Métricas · {{ $pagina->titulo }} · {{ $lab }}</title>
    <style>
        /* Tablas y no flex ni grid: la misma hoja sirve en pantalla y en el PDF. */
        *{box-sizing:border-box}
        body{font-family:{{ $paraPdf ? 'DejaVu Sans,sans-serif' : 'system-ui,-apple-system,Segoe UI,sans-serif' }};
             font-size:{{ $paraPdf ? '10px' : '14px' }};line-height:1.5;color:#1d1f1b;margin:0;background:{{ $paraPdf ? '#fff' : '#ecece6' }}}
        @if ($paraPdf) @page{margin:1.6cm 1.8cm 1.9cm} @endif
        .hoja{max-width:58rem;margin:{{ $paraPdf ? '0' : '1.5rem auto' }};background:#fff;padding:{{ $paraPdf ? '0' : '2rem 2.2rem' }};
              {{ $paraPdf ? '' : 'box-shadow:0 2px 14px rgba(0,0,0,.08);border-radius:6px' }}}
        table{width:100%;border-collapse:collapse}

        .membrete td{vertical-align:middle;padding:0 0 .5cm}
        .membrete td.marca img{height:1.3cm;width:auto}
        .membrete .lab{font-size:1.2em;font-weight:bold}
        .membrete .inst{font-size:.8em;color:#6e7066;letter-spacing:.12em;text-transform:uppercase}
        .membrete td.fecha{text-align:right;font-size:.85em;color:#6e7066;white-space:nowrap}
        .filete{height:2px;background:{{ $acento }};margin:0 0 .7cm}

        .rotulo{font-size:.78em;letter-spacing:.2em;text-transform:uppercase;color:{{ $acento }};font-weight:bold}
        h1{font-size:1.9em;margin:.1cm 0 .15cm;color:#111}
        .sub{color:#555;margin:0 0 .6cm}
        h2{font-size:1.1em;margin:.8cm 0 .3cm;color:#111}

        .cifras td{width:25%;padding:.35cm .3cm;border:1px solid #e3e3dc;vertical-align:top}
        .cifras .v{font-size:1.9em;font-weight:bold;color:#111;line-height:1.1}
        .cifras .k{font-size:.82em;color:#666}

        .tabla th,.tabla td{text-align:left;padding:.18cm .2cm;border-bottom:1px solid #e6e6df;vertical-align:top}
        .tabla th{font-size:.75em;text-transform:uppercase;letter-spacing:.08em;color:#777;border-bottom:1px solid #111}
        .tabla td.num,.tabla th.num{text-align:right;white-space:nowrap}
        .destino{font-size:.8em;color:#777;word-break:break-all}
        .barra{display:inline-block;height:.28cm;background:{{ $acento }};vertical-align:middle}
        .nota{font-size:.8em;color:#777;margin-top:.8cm;border-top:1px solid #e3e3dc;padding-top:.3cm}
        .vacio{color:#777;font-style:italic}

        .controles{max-width:58rem;margin:1.2rem auto 0;display:flex;flex-wrap:wrap;gap:.5rem;align-items:center}
        .controles a,.controles button{font:inherit;font-size:.9rem;padding:.45rem .9rem;border:1px solid #111;border-radius:4px;background:#fff;color:#111;text-decoration:none;cursor:pointer}
        .controles a.activo{background:#111;color:#fff}
        .controles .principal{background:{{ $acento }};border-color:{{ $acento }};color:#fff}
        .controles form{display:flex;gap:.4rem;align-items:center;margin:0}
        .controles input{font:inherit;font-size:.9rem;padding:.35rem .5rem;border:1px solid #bbb;border-radius:4px}
    </style>
</head>
<body>

@unless ($paraPdf)
    <div class="controles">
        @foreach (\App\Services\Analitica\MetricasDePagina::PERIODOS as $d => $texto)
            <a href="{{ route('paginas.metricas', [$pagina, 'dias' => $d]) }}" @class(['activo' => empty($consulta['desde']) && $dias === $d])>{{ $texto }}</a>
        @endforeach
        <form method="GET" action="{{ route('paginas.metricas', $pagina) }}">
            <input type="date" name="desde" value="{{ $desde->toDateString() }}" aria-label="Desde">
            <input type="date" name="hasta" value="{{ $hasta->toDateString() }}" aria-label="Hasta">
            <button type="submit">Ver</button>
        </form>
        <a class="principal" href="{{ route('paginas.metricas.pdf', [$pagina] + $consulta) }}">Descargar PDF para el cliente</a>
    </div>
@endunless

<div class="hoja">
    <table class="membrete"><tr>
        @if ($logo)
            <td class="marca"><img src="{{ $logo }}" alt="{{ $lab }}"></td>
        @else
            <td><div class="lab">{{ $lab }}</div>@if ($institucion)<div class="inst">{{ $institucion }}</div>@endif</td>
        @endif
        <td class="fecha">{{ $ciudad ? $ciudad . ', ' : '' }}{{ $fecha }}</td>
    </tr></table>
    <div class="filete"></div>

    <div class="rotulo">Informe de la página</div>
    <h1>{{ $pagina->titulo }}</h1>
    <p class="sub">
        {{ rtrim(config('app.url'), '/') }}/p/{{ $pagina->slug }}
        · del {{ $desde->format('d/m/Y') }} al {{ $hasta->format('d/m/Y') }}
    </p>

    <table class="cifras"><tr>
        <td><div class="v">{{ $n($personas) }}</div><div class="k">personas la visitaron</div></td>
        <td><div class="v">{{ $n($vistas) }}</div><div class="k">veces se abrió</div></td>
        <td><div class="v">{{ $n($pulsaron) }}</div><div class="k">personas pulsaron algún botón</div></td>
        <td><div class="v">{{ $pct($tasa) }}</div><div class="k">de quienes la vieron pulsaron</div></td>
    </tr></table>

    <h2>Qué pulsaron</h2>
    @if ($botones === [])
        <p class="vacio">Nadie pulsó botones ni enlaces de la página en este periodo.</p>
    @else
        <table class="tabla">
            <thead><tr><th>Botón o enlace</th><th class="num">Clics</th><th class="num">Personas</th><th class="num">De los visitantes</th></tr></thead>
            <tbody>
            @foreach ($botones as $b)
                <tr>
                    <td><strong>{{ $b['texto'] }}</strong>@if ($b['destino'])<div class="destino">→ {{ $b['destino'] }}</div>@endif</td>
                    <td class="num">{{ $n($b['clics']) }}</td>
                    <td class="num">{{ $n($b['personas']) }}</td>
                    <td class="num">{{ $pct($b['tasa']) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <h2>De dónde llegaron</h2>
    @if ($canales === [])
        <p class="vacio">Sin visitas en este periodo.</p>
    @else
        <table class="tabla">
            <thead><tr><th>Canal</th><th class="num">Personas</th></tr></thead>
            <tbody>
            @foreach ($canales as $canal => $cuantos)
                <tr><td>{{ $nombreDeCanal[$canal] ?? ucfirst((string) $canal) }}</td><td class="num">{{ $n($cuantos) }}</td></tr>
            @endforeach
            </tbody>
        </table>

        @if (count($fuentes) > 1)
            <table class="tabla" style="margin-top:.4cm">
                <thead><tr><th>Sitio o fuente</th><th class="num">Personas</th></tr></thead>
                <tbody>
                @foreach ($fuentes as $fuente => $cuantos)
                    <tr><td>{{ $fuente }}</td><td class="num">{{ $n($cuantos) }}</td></tr>
                @endforeach
                </tbody>
            </table>
        @endif

        {{-- Juntos: el título solo al pie de una hoja y su tabla en la otra se lee mal. --}}
        <div style="page-break-inside:avoid">
        <h2>Desde qué dispositivo</h2>
        <table class="tabla">
            <thead><tr><th>Dispositivo</th><th class="num">Personas</th></tr></thead>
            <tbody>
            @foreach ($dispositivos as $clave => $cuantos)
                <tr><td>{{ $nombreDeDispositivo[$clave] ?? ucfirst((string) $clave) }}</td><td class="num">{{ $n($cuantos) }}</td></tr>
            @endforeach
            </tbody>
        </table>
        </div>
    @endif

    <h2>Día por día</h2>
    <table class="tabla">
        <thead><tr><th>Día</th><th>Personas</th><th class="num">Clics</th></tr></thead>
        <tbody>
        @foreach ($porDia as $d)
            <tr>
                <td style="white-space:nowrap">{{ \Illuminate\Support\Carbon::parse($d['dia'])->translatedFormat('D d/m') }}</td>
                <td><span class="barra" style="width:{{ round($d['personas'] / $maxDia * ($paraPdf ? 9 : 14), 2) }}cm"></span> {{ $n($d['personas']) }}</td>
                <td class="num">{{ $n($d['clics']) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <p class="nota">
        Analítica propia de {{ $lab }}: sin cookies y sin datos que identifiquen a nadie. «Personas» cuenta visitantes
        distintos de cada día, así que en periodos largos quien vuelve otro día se cuenta otra vez. No incluye las visitas
        del equipo del laboratorio. Generado por fabOS el {{ now(config('fabos.lab.timezone'))->format('d/m/Y H:i') }}.
    </p>
</div>
</body>
</html>
