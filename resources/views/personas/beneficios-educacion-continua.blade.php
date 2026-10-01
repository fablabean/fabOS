@php
    /** @var array<string,mixed> $d */
    $paraPdf = $paraPdf ?? false;
    $num = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
    $porcentaje = fn (float $f) => round($f * 100) . ' %';
    $factorEstudiante = collect($d['programas'])->pluck('factor')->unique()->count() === 1
        ? (collect($d['programas'])->first()['factor'] ?? null)
        : null;
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Beneficios para estudiantes de Educación Continua · {{ $d['laboratorio'] }}</title>
    <style>
        /* Pensada para el PDF: tablas y no flex, letra con acentos, márgenes
           de hoja. En pantalla es la misma página, un poco más holgada. */
        *{box-sizing:border-box}
        @if ($paraPdf)
            @page{margin:1.6cm 1.8cm}
        @endif
        body{
            @if ($paraPdf)
                font-family:DejaVu Sans,sans-serif;font-size:10.2px;padding:0;
            @else
                font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;font-size:14px;padding:2rem 2.4rem;margin:0 auto;
            @endif
            line-height:1.55;color:#111;max-width:860px;background:#fff;
        }
        table{width:100%;border-collapse:collapse}
        .cabecera{border-bottom:2px solid #111;margin-bottom:1.2rem}
        .cabecera td{padding:0 0 .9rem;vertical-align:top}
        .cabecera td.marca{width:1px;padding-right:.9rem;vertical-align:middle}
        .cabecera td.marca img{height:1.6cm;max-width:4cm}
        h1{font-size:1.35rem;margin:0;line-height:1.25}
        .meta{font-size:.85rem;color:#555;margin-top:.25rem}
        /* Un título no se queda solo al pie de la página, lejos de su texto. */
        h2{page-break-after:avoid}
        h2{font-size:.8rem;letter-spacing:.08em;text-transform:uppercase;color:#0D6E63;margin:1.5rem 0 .5rem;border-bottom:1px solid #ddd;padding-bottom:.25rem}
        p{margin:.35rem 0 .55rem}
        ul,ol{margin:.3rem 0 .6rem;padding-left:1.2rem}
        li{margin-bottom:.25rem}
        .resumen{background:#f2f7f6;border-left:3px solid #0D6E63;padding:.7rem .95rem;margin:.4rem 0 .8rem}
        .resumen ul{margin:0}
        .tabla th,.tabla td{text-align:left;padding:.4rem .5rem;border-bottom:1px solid #ddd;vertical-align:top}
        .tabla th{font-size:.75em;text-transform:uppercase;letter-spacing:.05em;color:#555;background:#f6f6f2}
        .tabla td.num{text-align:right;white-space:nowrap}
        .tabla td b{font-size:1.05em}
        .chico{font-size:.88em;color:#555}
        .pie{margin-top:1.6rem;font-size:.82em;color:#666;border-top:1px solid #ddd;padding-top:.6rem}
        .aviso{background:#fff7e0;border:1px solid #f0d58c;padding:.6rem .9rem;border-radius:.4rem;margin-bottom:1.2rem;font-size:.9em}
    </style>
</head>
<body>

@unless ($paraPdf)
    <div class="aviso">Vista previa. El PDF sale igual, con las cifras vigentes en el momento de descargarlo.</div>
@endunless

<table class="cabecera">
    <tr>
        @if (! empty($logo))
            <td class="marca"><img src="{{ $logo }}" alt="{{ $d['laboratorio'] }}"></td>
        @endif
        <td>
            <h1>Beneficios para estudiantes de Educación Continua</h1>
            <div class="meta">{{ $d['firma'] }} · {{ $d['institucion'] }} · {{ $d['ciudad'] }}</div>
            <div class="meta">Para: Educación Continua · Valores vigentes al {{ $d['fecha'] }}</div>
        </td>
    </tr>
</table>

<div class="resumen">
    <ul>
        <li>Cada estudiante de un bootcamp, curso o diplomado de Educación Continua puede usar el {{ $d['laboratorio'] }} con <b>tarifa de estudiante</b>{{ $factorEstudiante !== null ? ' (' . $porcentaje($factorEstudiante) . ' de la tarifa base)' : '' }}.</li>
        <li>Al quedar inscrito recibe un <b>saldo de bienvenida</b> para empezar a usar las máquinas desde el primer día.</li>
        @if ($d['semanal']['activo'])
            <li>Además, cada lunes recibe un <b>beneficio semanal</b> de hasta {{ $num($d['semanal']['tope']) }} {{ $d['codigo'] }} ({{ $d['semanal']['topePesos'] }} de uso del laboratorio).</li>
        @endif
        <li>Para activarlo, Educación Continua envía al laboratorio la lista de estudiantes de cada programa (ver «Cómo se activa»).</li>
    </ul>
</div>

<h2>1 · Lo que recibe cada estudiante, según su programa</h2>
<table class="tabla">
    <thead>
        <tr>
            <th>Programa</th>
            <th>Saldo de bienvenida</th>
            <th>Beneficio semanal</th>
            <th>Tarifa</th>
            <th>Reserva con anticipación</th>
        </tr>
    </thead>
    <tbody>
    @foreach ($d['programas'] as $p)
        <tr>
            <td><b>{{ ucfirst($p['nombre']) }}</b></td>
            <td>{{ $num($p['bienvenida']) }} {{ $d['codigo'] }}<br><span class="chico">{{ $p['bienvenidaPesos'] }} de uso</span></td>
            <td>{{ $p['semanal'] && $d['semanal']['activo'] ? 'Sí, hasta ' . $num($d['semanal']['tope']) . ' ' . $d['codigo'] . ' por semana' : 'No' }}</td>
            <td>{{ $porcentaje($p['factor']) }} de la tarifa base</td>
            <td>{{ $p['reserva'] ? 'Hasta ' . $p['diasAntes'] . ' días' : 'No reserva por su cuenta' }}{{ $p['horasSemana'] ? ' · máx. ' . $p['horasSemana'] . ' h/semana' : '' }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
<p class="chico">
    La bienvenida se abona una sola vez por programa, en el momento en que la persona queda inscrita.
    @if ($d['general'])
        Como referencia, un estudiante de pregrado o posgrado empieza con {{ $num($d['general']['bienvenida']) }} {{ $d['codigo'] }}: los programas de Educación Continua empiezan con más porque traen más trabajo práctico.
    @endif
    @if ($d['factorExterno'] && $factorEstudiante)
        Una persona externa paga el {{ $porcentaje($d['factorExterno']) }} de la tarifa base: el estudiante paga {{ $num($d['factorExterno'] / $factorEstudiante) }} veces menos.
    @endif
</p>

<h2>2 · Qué son los {{ $d['moneda'] }}s</h2>
<p>
    El laboratorio maneja el uso de máquinas, materiales y asesorías con un saldo propio, el
    <b>{{ $d['moneda'] }} ({{ $d['codigo'] }})</b>. Un {{ $d['codigo'] }} equivale a {{ $d['pesosPorFbc'] }} de uso del
    laboratorio. No es dinero: no se cambia ni se devuelve en efectivo. Si el saldo no alcanza para un
    trabajo, la persona puede recargarlo con el laboratorio.
</p>
@if ($d['semanal']['activo'])
    <p>
        <b>El beneficio semanal no se acumula.</b> Cada lunes el sistema completa el saldo hasta
        {{ $num($d['semanal']['tope']) }} {{ $d['codigo'] }}: si a la persona le quedan 3, recibe 5; si tiene 8 o más, no recibe
        nada esa semana. Lo que no gastó no se pierde. Como orientación, una semana alcanza para
        {{ $d['semanal']['equivalencias'] }}.
    </p>
@endif

@if ($d['horasIncluidas']->isNotEmpty())
    <h2>3 · Horas incluidas con certificación</h2>
    <p>
        Quien aprueba el curso de una máquina recibe su certificación de uso (certifab) y, con ella, horas
        incluidas cada semana, que no se descuentan del saldo:
    </p>
    <ul>
        @foreach ($d['horasIncluidas'] as $h)
            <li>{{ $h['nombre'] }}: {{ $num($h['horas']) }} horas por semana.</li>
        @endforeach
    </ul>
    <p class="chico">Si las horas cubren todo el trabajo, no se cobra nada; lo que pase de ellas se cobra con la tarifa de estudiante. El material se cobra aparte, a costo.</p>
@endif

@if ($d['cursos']->isNotEmpty())
    <h2>{{ $d['horasIncluidas']->isNotEmpty() ? '4' : '3' }} · Formación sin costo</h2>
    <p>Los cursos que habilitan las máquinas no tienen costo para los estudiantes:</p>
    <ul>
        @foreach ($d['cursos'] as $c)
            @php $yaDiceElNivel = str_starts_with(mb_strtolower($c['nombre']), $c['nivel'] . ' '); @endphp
            <li>{{ $c['nombre'] }}@if (! $yaDiceElNivel || $c['tipo'] !== 'Curso') <span class="chico">({{ collect([$c['tipo'] !== 'Curso' ? mb_strtolower($c['tipo']) : null, $yaDiceElNivel ? null : 'nivel ' . $c['nivel']])->filter()->implode(', ') }})</span>@endif</li>
        @endforeach
    </ul>
    <p class="chico">El catálogo completo, con las fechas abiertas, está en {{ $d['sitio'] }}/formacion.</p>
@endif

@php $n = 3 + ($d['horasIncluidas']->isNotEmpty() ? 1 : 0) + ($d['cursos']->isNotEmpty() ? 1 : 0); @endphp

<h2>{{ $n }} · Asesorías</h2>
<p>
    Una asesoría con alguien del equipo del laboratorio cuesta {{ $num($d['asesoria']['fbc']) }} {{ $d['codigo'] }}
    ({{ $d['asesoria']['pesos'] }} de uso). Se pide desde el sitio y solo se descuenta si la asesoría se dio: si el
    estudiante no pudo asistir o no lo atendieron, el saldo vuelve.
</p>

<h2>{{ $n + 1 }} · Cómo se activa</h2>
<ol>
    <li>
        Al empezar cada programa, Educación Continua envía al laboratorio la lista de estudiantes con:
        <b>nombre completo</b>, <b>correo electrónico</b> (preferiblemente el institucional o el que usarán durante el
        programa), teléfono (opcional), <b>nombre del programa</b> y si es bootcamp, curso o diplomado, y las
        <b>fechas de inicio y de fin</b>.
    </li>
    <li>
        El laboratorio los inscribe. A cada estudiante se le crea su cuenta, si no la tenía, con su programa y su
        saldo de bienvenida.
    </li>
    <li>
        El estudiante entra en <b>{{ $d['sitio'] }}</b> con su correo: el sistema le envía un código, sin contraseña.
        Desde ahí reserva máquinas, se inscribe a los cursos y pide asesorías.
    </li>
    <li>
        Al terminar el programa, el laboratorio cierra la inscripción y la persona pasa a estudiante general.
        Conserva el saldo que le quede.
    </li>
</ol>

<h2>{{ $n + 2 }} · Condiciones</h2>
<ul>
    <li>El uso de cada máquina sigue las reglas del laboratorio: reserva previa, y certificación o acompañamiento según el equipo.</li>
    <li>Los materiales se cobran a costo, aparte del tiempo de máquina.</li>
    <li>Los valores de este documento son los vigentes al {{ $d['fecha'] }}. El laboratorio puede ajustarlos; cada vez que se descarga, este documento sale con los valores del día.</li>
</ul>

<div class="pie">
    {{ $d['firma'] }} · {{ $d['institucion'] }} · {{ $d['ciudad'] }} · {{ $d['sitio'] }}
</div>

</body>
</html>
