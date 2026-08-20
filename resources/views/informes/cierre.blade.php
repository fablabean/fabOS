@php
    $lab = config('fabos.lab.name');
    $tz = config('fabos.lab.timezone');
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
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Informe {{ $informe->titulo() }} · {{ $lab }}</title>
    <style>
        /* Se imprime o se exporta a PDF: es lo que se le entrega a la Universidad. */
        :root { --ink:#111; --soft:#666; --rule:#ddd; }
        *{box-sizing:border-box}
        body{
            font:14px/1.55 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
            color:var(--ink);margin:0;padding:2.5rem;max-width:900px;background:#fff;
        }
        header{display:flex;justify-content:space-between;align-items:flex-start;
               border-bottom:2px solid var(--ink);padding-bottom:1rem;margin-bottom:1.5rem}
        h1{font-size:1.4rem;margin:0}
        h2{font-size:1rem;text-transform:uppercase;letter-spacing:.08em;color:var(--soft);
           margin:2rem 0 .6rem;border-bottom:1px solid var(--rule);padding-bottom:.3rem}
        .periodo{font-size:1.1rem;font-weight:600;text-align:right}
        .meta{font-size:.85rem;color:var(--soft);text-align:right;margin-top:.2rem}
        .cifras{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin:.8rem 0 0}
        .cifra{border:1px solid var(--rule);border-radius:5px;padding:.7rem .8rem}
        .cifra b{display:block;font-size:1.5rem;letter-spacing:-.02em}
        .cifra span{font-size:.78rem;color:var(--soft)}
        table{width:100%;border-collapse:collapse;margin-top:.6rem}
        th,td{text-align:left;padding:.45rem .5rem;border-bottom:1px solid var(--rule)}
        th{font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:var(--soft)}
        td.num,th.num{text-align:right;white-space:nowrap}
        .nota{font-size:.8rem;color:var(--soft);margin-top:.5rem}
        .pie{font-size:.8rem;color:var(--soft);margin-top:3rem;border-top:1px solid var(--rule);
             padding-top:.8rem}
        @media print{ body{padding:0} .noimprimir{display:none} h2{break-after:avoid} }
    </style>
</head>
<body>

<header>
    <div>
        <h1>{{ $lab }}</h1>
        <div class="meta" style="text-align:left">Informe de operación</div>
    </div>
    <div>
        <div class="periodo">{{ $informe->titulo() }}</div>
        <div class="meta">Generado el {{ now()->timezone($tz)->format('d/m/Y H:i') }}</div>
    </div>
</header>

{{-- ------------------------------------------------------------------ uso --}}
<h2>Uso del laboratorio</h2>

<div class="cifras">
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
    Las horas se cuentan desde la llegada hasta la salida registradas en el equipo, no
    desde el bloque reservado. El aprovechamiento compara ambas cosas: si baja, es que
    la agenda se está bloqueando con reservas a las que nadie llega
    ({{ $informe->uso['no_show'] }} en este periodo).
</p>

@if ($informe->porArea->isNotEmpty())
    <table>
        <thead>
            <tr><th>Área</th><th class="num">Sesiones</th><th class="num">Personas</th><th class="num">Uso real</th></tr>
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
@endif

@if ($informe->equiposMasUsados->isNotEmpty())
    <h2>Equipos más usados</h2>
    <table>
        <thead>
            <tr><th>Equipo</th><th class="num">Sesiones</th><th class="num">Uso real</th></tr>
        </thead>
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
@endif

{{-- ------------------------------------------------------------ personas --}}
<h2>Comunidad</h2>

<table>
    <tbody>
        <tr>
            <td>Personas que usaron el laboratorio</td>
            <td class="num">{{ $informe->personas['atendidas'] }}</td>
        </tr>
        <tr>
            <td>Cuentas nuevas en el periodo</td>
            <td class="num">{{ $informe->personas['nuevas'] }}</td>
        </tr>
        <tr>
            <td>Sesiones con acompañamiento del equipo</td>
            <td class="num">{{ $informe->uso['con_acompanante'] }}</td>
        </tr>
        <tr>
            <td>Habilitaciones otorgadas (certifabs)</td>
            <td class="num">{{ $informe->formacion['certifabs'] }}</td>
        </tr>
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

{{-- ------------------------------------------------------ mantenimiento --}}
<h2>Mantenimiento</h2>

<table>
    <tbody>
        <tr>
            <td>Órdenes abiertas en el periodo</td>
            <td class="num">{{ $informe->mantenimiento['abiertas'] }}
                ({{ $informe->mantenimiento['correctivas'] }} correctivas,
                {{ $informe->mantenimiento['preventivas'] }} preventivas)</td>
        </tr>
        <tr>
            <td>Órdenes cerradas</td>
            <td class="num">{{ $informe->mantenimiento['cerradas'] }}</td>
        </tr>
        <tr>
            <td>Tiempo total de equipos fuera de servicio</td>
            <td class="num">{{ $horas($informe->mantenimiento['minutos_paro']) }}</td>
        </tr>
        <tr>
            <td>Órdenes todavía abiertas hoy</td>
            <td class="num">{{ $informe->mantenimiento['sin_resolver'] }}</td>
        </tr>
    </tbody>
</table>

{{-- ------------------------------------------------------------ finanzas --}}
<h2>FabCoins</h2>

<table>
    <tbody>
        <tr>
            <td>Emitido en el periodo (dotación, bonificación, recarga)</td>
            <td class="num">{{ $fbc($informe->finanzas['emitido']) }} {{ $moneda }}</td>
        </tr>
        <tr>
            <td>Consumo causado por uso de equipos</td>
            <td class="num">{{ $fbc($informe->finanzas['causado']) }} {{ $moneda }}</td>
        </tr>
        <tr>
            <td>Ventas de la tienda ({{ $informe->finanzas['n_ventas'] }})</td>
            <td class="num">{{ $fbc($informe->finanzas['ventas']) }} {{ $moneda }}</td>
        </tr>
        <tr>
            <td>Retenido hoy en garantías de reservas abiertas</td>
            <td class="num">{{ $fbc($informe->finanzas['retenido']) }} {{ $moneda }}</td>
        </tr>
    </tbody>
</table>

<p class="nota">
    Los {{ config('fabos.currency.name') }}s son la moneda interna del laboratorio: sirven
    para asignar y medir el uso de una capacidad limitada, no representan dinero de la
    Universidad.
</p>

{{-- ------------------------------------------------------------- compras --}}
<h2>Presupuesto y compras</h2>

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
    <p class="nota">
        Comprometido es lo aprobado que todavía no llega; ejecutado es lo recibido. Ambos
        se derivan de las solicitudes, no de un campo que alguien ajuste a mano.
    </p>
@else
    <p class="nota">No hay presupuestos vigentes cargados en el sistema.</p>
@endif

<p class="pie">
    Informe generado automáticamente por fabOS a partir de los datos con los que opera el
    laboratorio. No hay una tabla de estadísticas aparte: si el informe se desvía de la
    realidad, es que la realidad cambió.
</p>

<p class="noimprimir" style="margin-top:2rem">
    <button onclick="window.print()"
            style="font:inherit;padding:.5rem 1rem;border:1px solid var(--ink);background:#fff;
                   border-radius:4px;cursor:pointer">
        Imprimir o guardar como PDF
    </button>
</p>

</body>
</html>
