@php
    /** @var \App\Models\Acta $acta */
    $fecha = $acta->fecha?->copy()->locale('es');
    $linea = fn (?string $texto, string $ancho = '14em') => filled($texto)
        ? '<u>&nbsp;' . e($texto) . '&nbsp;</u>'
        : '<span style="display:inline-block;width:' . $ancho . ';border-bottom:1px solid #111">&nbsp;</span>';
    $responsable = $acta->baja_responsable ?: $acta->entrega?->name;
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acta de baja · {{ $acta->code }}</title>
    <style>
        /* El formato ADF-207-f3 de Administrativa y Financiera. */
        body{margin:0;background:#e8e8e2;padding:1.2rem;font-family:Arial,Helvetica,sans-serif;color:#111}
        .hoja{max-width:52rem;margin:0 auto;background:#fff;padding:1.8rem 2.2rem;box-shadow:0 2px 14px rgba(0,0,0,.12);font-size:14px;line-height:1.55}
        table{width:100%;border-collapse:collapse}
        td,th{border:1px solid #111;padding:4px 7px;vertical-align:middle;font-size:13px}
        .c{text-align:center}.b{font-weight:bold}
        .verde{color:#4e7a5b;font-weight:bold}
        .caja{display:inline-block;width:18px;height:16px;border:1px solid #111;text-align:center;line-height:16px;font-weight:bold;margin-right:.6rem}
        .motivos div{margin:.25rem 0 .25rem 1.4rem}
        .firmas{display:flex;gap:3rem;margin-top:4.5rem}
        .firmas div{flex:1;border-top:1px solid #111;padding-top:.3rem}
        .pie{display:flex;justify-content:space-between;color:#999;font-size:11px;margin-top:2rem;border-top:1px solid #ccc;padding-top:.3rem}
        .acciones{max-width:52rem;margin:0 auto .8rem;display:flex;gap:.5rem;justify-content:flex-end}
        .acciones a,.acciones button{font:inherit;font-size:14px;padding:.4rem .9rem;border-radius:6px;border:1px solid #999;background:#fff;color:#111;text-decoration:none;cursor:pointer}
        @media print{body{background:#fff;padding:0}.hoja{box-shadow:none;padding:0}.acciones{display:none}@page{margin:1.5cm}}
        @media (max-width:640px){body{padding:.5rem}.hoja{padding:1rem}.firmas{flex-direction:column;gap:3.5rem}}
    </style>
</head>
<body>
<div class="acciones">
    <a href="{{ route('actas.ver', $acta) }}">Ver el acta de movimiento (FRF-207-f7)</a>
    <button type="button" onclick="window.print()">Imprimir</button>
</div>
<div class="hoja">
    <table>
        <tr>
            <td rowspan="2" class="c b" style="width:24%;font-size:17px">{{ config('fabos.lab.institution') }}</td>
            <td class="c b">FORMATO</td>
            <td style="width:24%"><b>Versión:</b> 0</td>
        </tr>
        <tr>
            <td class="c verde">ACTA DE BAJA DE ACTIVOS No. {{ $acta->code }}</td>
            <td><b>Código:</b> ADF-207-f3</td>
        </tr>
        <tr><td colspan="2" style="border:0"></td><td><b>Fecha:</b> 11/Feb/2021</td></tr>
    </table>

    <p style="margin-top:1.6rem">
        En la ciudad de Bogotá D. C., a los {!! $linea($fecha?->format('j'), '3em') !!} días del mes de
        {!! $linea($fecha?->translatedFormat('F'), '7em') !!} de {!! $linea($fecha?->format('Y'), '4em') !!},
        se da de baja {{ $acta->items->count() === 1 ? 'el siguiente elemento' : 'los siguientes elementos' }}:
    </p>

    <table>
        <tr><th style="width:5%">#</th><th style="width:15%">Placa</th><th>Descripción</th><th style="width:15%">Marca</th><th style="width:14%">Modelo</th><th style="width:15%">Serie</th></tr>
        @forelse ($acta->items as $n => $item)
            <tr>
                <td class="c">{{ $n + 1 }}</td><td>{{ $item->placa }}</td><td>{{ $item->descripcion }}</td>
                <td>{{ $item->marca }}</td><td>{{ $item->modelo }}</td><td>{{ $item->serie }}</td>
            </tr>
        @empty
            <tr><td colspan="6" style="height:2rem"></td></tr>
        @endforelse
    </table>

    @if (filled($acta->observaciones))
        <p style="white-space:pre-line"><b>Detalle:</b> {{ $acta->observaciones }}</p>
    @endif

    <p style="margin-top:1.2rem">El motivo por el cual se dan de baja estos activos es:</p>
    <div class="motivos">
        @foreach (\App\Models\Acta::MOTIVOS_DE_BAJA as $clave => $texto)
            <div><span class="caja">{{ $acta->baja_motivo === $clave ? 'X' : '' }}</span>{{ $texto }}</div>
        @endforeach
    </div>

    <p style="margin-top:1.2rem">
        Para el efecto se reunieron en las oficinas de la carrera 11 Nº 78 - 47, el Vicerrector Administrativo y
        Financiero Dr.(a) {!! $linea($acta->baja_vicerrector, '16em') !!} y el (cargo) {!! $linea($acta->baja_responsable_cargo, '10em') !!}
        Dr.(a) {!! $linea($responsable, '14em') !!} del proceso {!! $linea($acta->baja_proceso, '16em') !!}
        responsable del manejo y administración de los activos a dar de baja.
    </p>
    <p>
        Igualmente el Vicerrector Administrativo y Financiero, autoriza el registro contable correspondiente a la baja
        de estos elementos.
    </p>
    <p>Para constancia se firma por los que en ella intervinieron.</p>

    <div class="firmas">
        <div>Vicerrector Administrativo y Financiero<br><span style="font-size:12px">{{ $acta->baja_vicerrector }}</span></div>
        <div>{{ $acta->baja_responsable_cargo ?: '(Cargo)' }}<br><span style="font-size:12px">{{ $responsable }}</span></div>
    </div>

    <p style="margin-top:1.6rem;font-size:13px"><b>Anexo:</b> acta de movimiento {{ $acta->code }} (FRF-207-f7) con el estado de cada activo.</p>

    <div class="pie">
        <span>DOCUMENTO ORIGINAL – ADF-207-f3 · generado por FabOS</span>
        <span>Pág. 1 de 1</span>
    </div>
</div>
</body>
</html>
