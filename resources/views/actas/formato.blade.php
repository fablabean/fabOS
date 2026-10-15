@php
    /** @var \App\Models\Acta $acta */
    $paraPdf = $paraPdf ?? false;
    $soloCuerpo = $soloCuerpo ?? false;
    $tz = config('fabos.lab.timezone');
    $x = fn (bool $marcado) => $marcado ? 'X' : '';
    // El formato trae trece renglones para activos: se rellenan hasta ahí, y
    // si son más, crece.
    $renglones = max(13, $acta->items->count());
    $firmo = fn (string $parte) => $acta->{"{$parte}_firmo_at"}?->timezone($tz)->format('d/m/Y H:i');
@endphp
@unless ($soloCuerpo)
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $acta->code }} · Control para el movimiento de activos</title>
@endunless
    <style>
        /* El formato FRF-207-f7, celda por celda. Tablas y no flex: es lo que
           entiende el generador de PDF, y un formato de casillas es una tabla. */
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
        .frf .caja{display:inline-block;width:{{ $paraPdf ? '16px' : '20px' }};height:{{ $paraPdf ? '12px' : '15px' }};border:1px solid #111;text-align:center;font-weight:bold;line-height:{{ $paraPdf ? '12px' : '15px' }}}
        .frf .caja.si{background:#111;color:#fff}
        .frf .tipos td{border:0;padding:{{ $paraPdf ? '2px 4px' : '3px 6px' }};{{ $paraPdf ? 'white-space:nowrap' : '' }}}
        .frf .marco{border:1px solid #111}
        .frf .firma{height:{{ $paraPdf ? '46px' : '64px' }};text-align:center;vertical-align:bottom}
        .frf .firma img{max-height:{{ $paraPdf ? '40px' : '56px' }};max-width:90%}
        .frf .pie td{border:0;padding:{{ $paraPdf ? '1.5px 4px' : '2px 6px' }}}
        .frf .nota{font-size:.92em;color:#444}
        @if ($paraPdf)
            @page{margin:1cm 1.1cm}
            body{margin:0}
        @elseif (! $soloCuerpo)
            body{margin:0;background:#e8e8e2;padding:1.2rem}
            .hoja{max-width:54rem;margin:0 auto;background:#fff;padding:1.4rem;box-shadow:0 2px 14px rgba(0,0,0,.12)}
            @media print{body{background:#fff;padding:0}.hoja{box-shadow:none;padding:0}.noimprimir{display:none}}
        @endif
    </style>
@unless ($soloCuerpo)
</head>
<body>
<div class="hoja">
@endunless

<div class="frf">
    {{-- ------------------------------------------------ cabecera --}}
    <table>
        <tr>
            <td rowspan="2" style="width:19%" class="c b">{{ config('fabos.lab.institution') }}</td>
            <td rowspan="2" class="c b" style="font-size:1.15em">FORMATO</td>
            <td style="width:25%"><span class="b">Versión:</span> 1</td>
        </tr>
        <tr>
            <td><span class="b">Código:</span> <span class="verde">FRF-207-f7</span></td>
        </tr>
        <tr>
            <td colspan="2" class="c verde" style="font-size:1.1em">CONTROL PARA EL MOVIMIENTO DE ACTIVOS</td>
            <td><span class="b">Fecha:</span> 18/Mar/2014</td>
        </tr>
    </table>

    <div class="sep"></div>

    {{-- ------------------------------------------------ fecha --}}
    <table style="width:62%">
        <tr>
            <td class="gris" style="width:38%">FECHA DEL MOVIMIENTO</td>
            <td style="width:8%">DD</td><td class="d" style="width:9%">{{ $acta->fecha->format('j') }}</td>
            <td style="width:8%">MM</td><td class="d" style="width:9%">{{ $acta->fecha->format('n') }}</td>
            <td class="b" style="width:10%">AAAA</td><td class="d">{{ $acta->fecha->format('Y') }}</td>
        </tr>
    </table>

    <div class="sep"></div>

    {{-- ------------------------------------------------ tipo --}}
    <table>
        <tr><td class="gris">TIPO DE MOVIMIENTO</td></tr>
        <tr>
            <td style="padding:0">
                <div style="padding:{{ $paraPdf ? '3px 4px 1px' : '4px 6px 2px' }}">Por favor marque con una X</div>
                <table class="tipos">
                    @foreach (\App\Models\Acta::COLUMNA_IZQUIERDA as $n => $izquierda)
                        @php $derecha = \App\Models\Acta::COLUMNA_DERECHA[$n] ?? null; @endphp
                        <tr>
                            <td style="width:42%">{{ mb_strtoupper(\App\Models\Acta::TIPOS[$izquierda]) }}</td>
                            <td style="width:8%"><span class="caja {{ $acta->tipo === $izquierda ? 'si' : '' }}">{{ $x($acta->tipo === $izquierda) }}</span></td>
                            @if ($derecha)
                                <td style="width:42%">{{ mb_strtoupper(\App\Models\Acta::TIPOS[$derecha]) }}</td>
                                <td><span class="caja {{ $acta->tipo === $derecha ? 'si' : '' }}">{{ $x($acta->tipo === $derecha) }}</span></td>
                            @else
                                <td colspan="2">Especificar: <span style="border-bottom:1px solid #111;padding:0 2.5em">{{ $acta->tipo === 'otro' ? $acta->tipo_otro : '' }}</span></td>
                            @endif
                        </tr>
                    @endforeach
                </table>
            </td>
        </tr>
    </table>

    <div class="sep"></div>

    {{-- ------------------------------------------------ origen y destino --}}
    <table class="sin">
        <tr>
            @foreach (['entrega' => 'ÁREA DE ORIGEN - Entrega', 'recibe' => 'ÁREA DE DESTINO - Recibe'] as $parte => $titulo)
                <td style="padding:0;{{ $parte === 'entrega' ? 'padding-right:2.4%' : 'padding-left:2.4%' }};width:50%">
                    <table class="con">
                        <tr><td colspan="2" class="gris">{{ $titulo }}</td></tr>
                        <tr><td style="width:30%">Nombre Completo</td><td>{{ $acta->{"{$parte}_nombre"} }}</td></tr>
                        <tr><td>Proceso</td><td>{{ $acta->{"{$parte}_proceso"} }}</td></tr>
                        <tr><td>Cedula</td><td>{{ $acta->{"{$parte}_cedula"} }}</td></tr>
                        <tr><td>Sede</td><td>{{ $acta->{"{$parte}_sede"} }}</td></tr>
                    </table>
                </td>
            @endforeach
        </tr>
    </table>

    <div class="sep"></div>

    {{-- ------------------------------------------------ activos --}}
    <table>
        <tr>
            <td class="gris" style="width:88%">INFORMACIÓN DE LOS ACTIVOS</td>
            <td class="c b">ESTADO</td>
        </tr>
    </table>
    <table style="margin-top:-1px">
        <tr class="c b">
            <td style="width:12%">PLACA DE INVENTARIO</td>
            <td style="width:33%">DESCRIPCIÓN</td>
            <td style="width:13%">MARCA</td>
            <td style="width:10%">MODELO</td>
            <td style="width:20%">SERIE</td>
            <td style="width:4%">B</td><td style="width:4%">R</td><td style="width:4%">M</td>
        </tr>
        @for ($i = 0; $i < $renglones; $i++)
            @php $item = $acta->items[$i] ?? null; @endphp
            <tr class="c">
                <td class="b">{{ $item?->placa }}&nbsp;</td>
                <td class="b">{{ $item ? mb_strtoupper($item->descripcion) : '' }}</td>
                <td class="b">{{ $item ? mb_strtoupper((string) $item->marca) : '' }}</td>
                <td class="b">{{ $item?->modelo }}</td>
                <td class="b">{{ $item?->serie }}</td>
                <td>{{ $x($item?->estado === 'B') }}</td>
                <td>{{ $x($item?->estado === 'R') }}</td>
                <td>{{ $x($item?->estado === 'M') }}</td>
            </tr>
        @endfor
    </table>

    {{-- ------------------------------------------------ observaciones --}}
    <table style="margin-top:-1px">
        <tr>
            <td style="height:{{ $paraPdf ? '52px' : '74px' }};vertical-align:top">
                <span class="b" style="font-style:italic">OBSERVACIONES:</span>
                @if ($acta->fecha_retorno)
                    Fecha prevista de retorno: {{ $acta->fecha_retorno->format('d/m/Y') }}.
                @endif
                {!! nl2br(e((string) $acta->observaciones)) !!}
            </td>
        </tr>
    </table>

    <div class="sep"></div>

    {{-- ------------------------------------------------ firmas --}}
    <table class="sin">
        <tr>
            {{-- Dentro de la Universidad no hay nada que autorizar: la casilla
                 de Planta Física queda dicha como que no aplica, en vez de con
                 un nombre que parecería una firma pendiente. --}}
            <td class="firma b" style="width:30%">{{ $acta->saleDeLaUniversidad() ? $config['autoriza_nombre'] : '' }}</td>
            @foreach (['entrega', 'recibe'] as $parte)
                <td class="firma" style="width:35%">
                    @if ($firmas[$parte] ?? null)
                        <img src="{{ $firmas[$parte] }}" alt="Firma de {{ $acta->{"{$parte}_nombre"} }}">
                    @endif
                </td>
            @endforeach
        </tr>
    </table>
    <table class="pie">
        <tr class="b">
            <td style="width:30%;border-top:1px solid #111">AUTORIZADO POR (Firma)</td>
            <td style="width:35%;border-top:1px solid #111">FIRMA DE QUIEN ENTREGA</td>
            <td style="border-top:1px solid #111">FIRMA DE QUIEN RECIBE</td>
        </tr>
        <tr>
            <td>
                @if ($acta->saleDeLaUniversidad())
                    <span class="b">NOMBRE:</span> {{ $config['autoriza_nombre'] }}
                @else
                    <span class="b">NO APLICA</span>
                @endif
            </td>
            <td class="c">{{ $acta->entrega_nombre }}</td>
            <td class="c">{{ $acta->recibe_nombre }}</td>
        </tr>
        <tr>
            <td>
                @if ($acta->saleDeLaUniversidad())
                    <span class="b">CARGO:</span> {{ $config['autoriza_cargo'] }}
                @else
                    Movimiento dentro de la Universidad
                @endif
            </td>
            <td class="c">{{ $acta->entrega_proceso }}</td>
            <td class="c">{{ $acta->recibe_proceso }}</td>
        </tr>
        <tr class="b">
            <td></td>
            <td>PROCESO / UNIDAD</td>
            <td>PROCESO / UNIDAD</td>
        </tr>
        {{-- Lo que deja la firma en pantalla: cuándo, y con qué acta. --}}
        <tr class="nota">
            <td>{{ $acta->code }}</td>
            <td>{{ $firmo('entrega') ? 'Firmado en ' . config('fabos.lab.name') . ' el ' . $firmo('entrega') : 'Sin firmar' }}</td>
            <td>{{ $firmo('recibe') ? 'Firmado en ' . config('fabos.lab.name') . ' el ' . $firmo('recibe') : 'Sin firmar' }}</td>
        </tr>
    </table>

    <div class="sep"></div>

    <table>
        <tr>
            <td>
                <span class="b">IMPORTANTE</span>:
                @if ($acta->saleDeLaUniversidad())
                    Para realizar cualquier movimiento de ACTIVOS, este formato debe tener la firma autorizada.
                @else
                    Movimiento dentro de las instalaciones de la Universidad: no requiere la firma de autorización de Planta Física.
                @endif
            </td>
        </tr>
    </table>
</div>

@unless ($soloCuerpo)
    @unless ($paraPdf)
        @if ($enlacePdf ?? null)
            <p class="noimprimir" style="margin:1.2rem 0 0;font-family:system-ui,sans-serif">
                <a href="{{ $enlacePdf }}" style="display:inline-block;background:#111;color:#fff;padding:.6rem 1.1rem;border-radius:4px;text-decoration:none;font-weight:600">Descargar PDF</a>
            </p>
        @endif
    @endunless
</div>
</body>
</html>
@endunless
