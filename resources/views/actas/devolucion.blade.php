@php
    /** @var \App\Models\ActaDevolucion $devolucion */
    /** @var \App\Models\Acta $acta */
    $paraPdf = $paraPdf ?? false;
    $tz = config('fabos.lab.timezone');
    $x = fn (bool $marcado) => $marcado ? 'X' : '';
    $renglones = max(8, $devolucion->items->count());
    $pendientes = $faltan > 0 ? $acta->pendientesDeVolver()->get() : collect();
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $devolucion->code }} · Devolución de activos</title>
    <style>
        /* De la misma familia que el FRF-207-f7 al que acompaña: mismas
           casillas, mismas tablas. Quien archiva los dos los reconoce juntos. */
        .frf{font-family:DejaVu Sans,Arial,Helvetica,sans-serif;font-size:{{ $paraPdf ? '8.4px' : '11.5px' }};color:#111;line-height:1.3;background:#fff}
        .frf *{box-sizing:border-box}
        .frf table{width:100%;border-collapse:collapse;table-layout:fixed}
        .frf td,.frf th{border:1px solid #111;padding:{{ $paraPdf ? '2.5px 4px' : '4px 6px' }};vertical-align:middle;word-wrap:break-word}
        .frf .sin td{border:0}
        .frf .sin .con td{border:1px solid #111}
        .frf .c{text-align:center}
        .frf .d{text-align:right}
        .frf .b{font-weight:bold}
        .frf .gris{background:#bfbfbf;font-weight:bold;text-align:center}
        .frf .verde{color:#3f6b4f;font-weight:bold}
        .frf .sep{height:{{ $paraPdf ? '5px' : '8px' }}}
        .frf .nota-item{font-weight:normal;font-style:italic;font-size:.92em;color:#333}
        .frf .peor{background:#f3d9d9}
        .frf .firma{height:{{ $paraPdf ? '46px' : '64px' }};text-align:center;vertical-align:bottom}
        .frf .firma img{max-height:{{ $paraPdf ? '40px' : '56px' }};max-width:90%}
        .frf .pie td{border:0;padding:{{ $paraPdf ? '1.5px 4px' : '2px 6px' }}}
        .frf .nota{font-size:.92em;color:#444}
        @if ($paraPdf)
            @page{margin:1cm 1.1cm}
            body{margin:0}
        @else
            body{margin:0;background:#e8e8e2;padding:1.2rem;font-family:system-ui,sans-serif}
            .hoja{max-width:54rem;margin:0 auto;background:#fff;padding:1.4rem;box-shadow:0 2px 14px rgba(0,0,0,.12)}
            .barra{max-width:54rem;margin:1rem auto 0;display:flex;gap:.6rem;flex-wrap:wrap;align-items:center}
            .barra a,.barra button{display:inline-block;padding:.6rem 1.1rem;border-radius:4px;text-decoration:none;font-weight:600;font:inherit;font-weight:600;cursor:pointer;border:1px solid #111;background:#fff;color:#111}
            .barra .principal{background:#111;color:#fff}
            .barra .aviso{font-size:.9rem;color:#444}
            .mensaje{max-width:54rem;margin:0 auto 1rem;padding:.8rem 1rem;border-radius:6px;font-size:.95rem}
            .mensaje.ok{background:#d9efe9}
            .mensaje.error{background:#f3d9d9}
            @media print{body{background:#fff;padding:0}.hoja{box-shadow:none;padding:0}.barra,.mensaje{display:none}}
        @endif
    </style>
</head>
<body>

@unless ($paraPdf)
    @if (session('status'))<div class="mensaje ok">{{ session('status') }}</div>@endif
    @if ($errors->any())<div class="mensaje error">{{ $errors->first() }}</div>@endif
@endunless

<div class="hoja">
<div class="frf">
    <table>
        <tr>
            <td rowspan="2" style="width:19%" class="c b">{{ config('fabos.lab.institution') }}</td>
            <td rowspan="2" class="c b" style="font-size:1.15em">FORMATO</td>
            <td style="width:25%"><span class="b">Devolución:</span> {{ $devolucion->code }}</td>
        </tr>
        <tr>
            <td><span class="b">Acta:</span> <span class="verde">{{ $acta->code }}</span></td>
        </tr>
        <tr>
            <td colspan="2" class="c verde" style="font-size:1.1em">DEVOLUCIÓN DE ACTIVOS</td>
            <td>Acompaña al FRF-207-f7</td>
        </tr>
    </table>

    <div class="sep"></div>

    <table style="width:62%">
        <tr>
            <td class="gris" style="width:38%">FECHA DE DEVOLUCIÓN</td>
            <td style="width:8%">DD</td><td class="d" style="width:9%">{{ $devolucion->fecha->format('j') }}</td>
            <td style="width:8%">MM</td><td class="d" style="width:9%">{{ $devolucion->fecha->format('n') }}</td>
            <td class="b" style="width:10%">AAAA</td><td class="d">{{ $devolucion->fecha->format('Y') }}</td>
        </tr>
    </table>

    <div class="sep"></div>

    <table>
        <tr><td colspan="2" class="gris">MOVIMIENTO QUE SE DEVUELVE</td></tr>
        <tr>
            <td style="width:70%"><span class="b">Tipo:</span> {{ mb_strtoupper($acta->tipoLegible()) }}</td>
            <td><span class="b">Salió el:</span> {{ $acta->fecha->format('d/m/Y') }}</td>
        </tr>
    </table>

    <div class="sep"></div>

    <table class="sin">
        <tr>
            <td style="padding:0;padding-right:2.4%;width:50%">
                <table class="con">
                    <tr><td colspan="2" class="gris">DEVUELVE</td></tr>
                    <tr><td style="width:30%">Nombre Completo</td><td>{{ $devolucion->devuelve_nombre }}</td></tr>
                    <tr><td>Cedula</td><td>{{ $devolucion->devuelve_cedula }}</td></tr>
                </table>
            </td>
            <td style="padding:0;padding-left:2.4%;width:50%">
                <table class="con">
                    <tr><td colspan="2" class="gris">RECIBE EN EL LABORATORIO</td></tr>
                    <tr><td style="width:30%">Nombre Completo</td><td>{{ $devolucion->recibe_nombre }}</td></tr>
                    <tr><td>Cedula</td><td>{{ $devolucion->recibe_cedula }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="sep"></div>

    {{-- El título en su propia tabla: la de las columnas tiene que empezar por
         la fila que define sus anchos, o el generador de PDF los reparte por
         igual. --}}
    <table>
        <tr>
            <td class="gris" style="width:76%">ACTIVOS QUE REGRESAN</td>
            <td class="c b" style="width:12%">SALIÓ</td>
            <td class="c b">VOLVIÓ</td>
        </tr>
    </table>
    <table style="margin-top:-1px">
        <tr class="c b">
            <td style="width:11%">PLACA DE INVENTARIO</td>
            <td style="width:29%">DESCRIPCIÓN</td>
            <td style="width:11%">MARCA</td>
            <td style="width:9%">MODELO</td>
            <td style="width:16%">SERIE</td>
            <td style="width:4%">B</td><td style="width:4%">R</td><td style="width:4%">M</td>
            <td style="width:4%">B</td><td style="width:4%">R</td><td style="width:4%">M</td>
        </tr>
        @for ($i = 0; $i < $renglones; $i++)
            @php $item = $devolucion->items[$i] ?? null; @endphp
            <tr class="c">
                <td class="b">{{ $item?->placa }}&nbsp;</td>
                <td class="b">
                    {{ $item ? mb_strtoupper($item->descripcion) : '' }}
                    @if ($item?->nota_devolucion)<div class="nota-item">{{ $item->nota_devolucion }}</div>@endif
                </td>
                <td class="b">{{ $item ? mb_strtoupper((string) $item->marca) : '' }}</td>
                <td class="b">{{ $item?->modelo }}</td>
                <td class="b">{{ $item?->serie }}</td>
                <td>{{ $x($item?->estado === 'B') }}</td>
                <td>{{ $x($item?->estado === 'R') }}</td>
                <td>{{ $x($item?->estado === 'M') }}</td>
                {{-- Si volvió peor de como salió, se nota de un vistazo. --}}
                <td class="{{ $item?->volvioPeor() ? 'peor' : '' }}">{{ $x($item?->estado_devolucion === 'B') }}</td>
                <td class="{{ $item?->volvioPeor() ? 'peor' : '' }}">{{ $x($item?->estado_devolucion === 'R') }}</td>
                <td class="{{ $item?->volvioPeor() ? 'peor' : '' }}">{{ $x($item?->estado_devolucion === 'M') }}</td>
            </tr>
        @endfor
    </table>
    <table style="margin-top:-1px">
        <tr>
            <td style="height:{{ $paraPdf ? '46px' : '64px' }};vertical-align:top">
                <span class="b" style="font-style:italic">OBSERVACIONES:</span>
                {!! nl2br(e((string) $devolucion->observaciones)) !!}
            </td>
        </tr>
        <tr>
            <td>
                <span class="b">ESTADO DEL ACTA {{ $acta->code }}:</span>
                @if ($faltan === 0)
                    Con esta devolución regresó todo lo que había salido.
                @else
                    {{ $faltan === 1 ? 'Queda un activo por regresar' : 'Quedan ' . $faltan . ' activos por regresar' }}:
                    {{ $pendientes->map(fn ($p) => $p->descripcion . ($p->placa ? ' (' . $p->placa . ')' : ''))->implode(', ') }}.
                @endif
            </td>
        </tr>
    </table>

    <div class="sep"></div>

    <table class="sin">
        <tr>
            <td class="firma" style="width:50%"></td>
            <td class="firma">
                @if ($firma)<img src="{{ $firma }}" alt="Firma de {{ $devolucion->recibe_nombre }}">@endif
            </td>
        </tr>
    </table>
    <table class="pie">
        <tr class="b">
            <td style="width:50%;border-top:1px solid #111">DEVUELVE</td>
            <td style="border-top:1px solid #111">FIRMA DE QUIEN RECIBE</td>
        </tr>
        <tr>
            <td>{{ $devolucion->devuelve_nombre }}</td>
            <td>{{ $devolucion->recibe_nombre }}</td>
        </tr>
        <tr class="nota">
            <td>Entregó los activos en el laboratorio.</td>
            <td>{{ $devolucion->firmo_at ? 'Firmado en ' . config('fabos.lab.name') . ' el ' . $devolucion->firmo_at->timezone($tz)->format('d/m/Y H:i') : 'Sin firmar' }}</td>
        </tr>
    </table>
</div>
</div>

@unless ($paraPdf)
    <div class="barra">
        <a class="principal" href="{{ route('actas.devolucion.pdf', $devolucion) }}">Descargar PDF</a>
        <a href="{{ route('actas.ver', $acta) }}">Ver el acta {{ $acta->code }}</a>

        {{-- Avisar a Planta Física es opcional, y solo de lo que ellos
             conocen: lo que había salido de la Universidad. --}}
        @if ($acta->saleDeLaUniversidad())
            <form method="POST" action="{{ route('actas.devolucion.enviar', $devolucion) }}" style="margin:0"
                  onsubmit="return confirm('Se le envía el formato de devolución a {{ $config['autoriza_nombre'] }} ({{ $config['correo_planta'] }}). ¿Enviar?')">
                @csrf
                <button type="submit">{{ $devolucion->enviada_a_planta_at ? 'Volver a enviar a Planta Física' : 'Enviar a Planta Física' }}</button>
            </form>
            @if ($devolucion->enviada_a_planta_at)
                <span class="aviso">Enviada a {{ $devolucion->enviada_a_planta_a }} el {{ $devolucion->enviada_a_planta_at->timezone($tz)->format('d/m/Y H:i') }}.</span>
            @endif
        @else
            <span class="aviso">Movimiento dentro de la Universidad: no se envía a Planta Física.</span>
        @endif
    </div>
@endunless

</body>
</html>
