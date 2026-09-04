@php
    $lab = config('fabos.lab.name');
    $simbolo = config('fabos.money.symbol');
    // La de esta solicitud: no todo lleva el IVA general.
    $iva = $solicitud->tasaDeImpuesto();
    $tz = config('fabos.lab.timezone');
    $pesos = fn ($v) => $simbolo . number_format((float) $v, 0, ',', '.');
    // En la moneda de la solicitud: si vino en dólares, se pide en dólares y
    // al final se dice a cuántos pesos va.
    $moneda = fn ($v) => $solicitud->formato((float) $v);
    $cantidad = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, ',', '.'), '0'), ',');
    // La misma plantilla sirve la pantalla y el PDF: lo que se ve es lo que se
    // baja. En el PDF no hay botones, y la letra es una que el generador trae
    // con acentos y eñes.
    $paraPdf = $paraPdf ?? false;
    $enlacePdf = $enlacePdf ?? null;
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $solicitud->code }} · Requisición · {{ $lab }}</title>
    <style>
        /* Pensada para imprimirse o bajarse en PDF: es lo que se le entrega al
           área de compras, así que tiene que verse bien en papel. Todo va en
           tablas y no en flex ni grid, porque el generador de PDF no los
           entiende y la hoja saldría desarmada. */
        *{box-sizing:border-box}
        @if ($paraPdf)
            @page{margin:1.1cm 1.4cm}
        @endif
        body{
            @if ($paraPdf)
                font-family:DejaVu Sans,sans-serif;font-size:11px;padding:0;
            @else
                font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;font-size:14px;padding:2.5rem;
            @endif
            line-height:1.5;color:#111;margin:0;max-width:900px;background:#fff;
        }
        table{width:100%;border-collapse:collapse}
        .cabecera{border-bottom:2px solid #111;margin-bottom:1.5rem}
        .cabecera td{padding:0 0 1rem;vertical-align:top}
        h1{font-size:1.4rem;margin:0}
        .codigo{font-size:1.6rem;font-weight:700;text-align:right}
        .meta{font-size:.85rem;color:#666;margin-top:.2rem}
        .derecha{text-align:right}
        .datos{margin-bottom:1.5rem;font-size:.9rem}
        .datos td{padding:.15rem 0;vertical-align:top}
        .datos td.k{color:#666;width:9rem;padding-right:1rem;white-space:nowrap}
        .datos td.v{font-weight:bold}
        .lineas{margin-bottom:1.5rem}
        .lineas th,.lineas td{text-align:left;padding:.55rem .5rem;border-bottom:1px solid #ddd;vertical-align:top}
        .lineas th{font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:#666;border-bottom:1px solid #111}
        td.num,th.num{text-align:right;white-space:nowrap}
        .lineas tfoot td{border-bottom:none;padding-top:.5rem}
        .lineas tfoot tr.total td{font-weight:700;font-size:1.05rem;border-top:2px solid #111}
        .enlace{font-size:.78rem;color:#666;word-wrap:break-word;word-break:break-all}
        .carrito{border:1px solid #111;padding:.8rem 1rem;margin:0 0 1.5rem}
        .carrito .titulo{font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:#666;margin-bottom:.2rem}
        .carrito .url{font-family:'DejaVu Sans Mono',Menlo,Consolas,monospace;font-size:.85rem;word-wrap:break-word;word-break:break-all}
        .firmas{margin-top:3rem}
        .firmas td{width:50%;padding:0 1.5rem 0 0}
        .firmas td+td{padding:0 0 0 1.5rem}
        .firma{border-top:1px solid #111;padding-top:.4rem;font-size:.82rem;color:#666}
        .nota{font-size:.8rem;color:#666;margin-top:1.5rem;border-top:1px solid #ddd;padding-top:.8rem}
        .sello{display:inline-block;border:1px solid #111;border-radius:3px;
               padding:.15rem .5rem;font-size:.75rem;text-transform:uppercase;letter-spacing:.06em}
        .boton{font:inherit;display:inline-block;padding:.5rem 1rem;border:1px solid #111;background:#fff;
               border-radius:4px;cursor:pointer;color:#111;text-decoration:none;margin-right:.5rem}
        .boton.principal{background:#111;color:#fff}
        @media print{ body{padding:0} .noimprimir{display:none} }
    </style>
</head>
<body>

<table class="cabecera">
    <tr>
        <td>
            <h1>{{ $lab }}</h1>
            <div class="meta">Requisición de compra</div>
        </td>
        <td class="derecha">
            <div class="codigo">{{ $solicitud->code }}</div>
            <div class="meta">
                <span class="sello">{{ \App\Models\PurchaseRequest::ESTADOS[$solicitud->status] ?? $solicitud->status }}</span>
            </div>
        </td>
    </tr>
</table>

<table class="datos">
    <tr>
        <td class="k">Solicita</td>
        <td class="v">{{ $solicitud->requestedBy?->name }} · {{ $solicitud->requestedBy?->email }}</td>
    </tr>
    @if ($solicitud->area)
        <tr><td class="k">Área</td><td class="v">{{ $solicitud->area->name }}</td></tr>
    @endif
    @if ($solicitud->project)
        <tr><td class="k">Proyecto</td><td class="v">{{ $solicitud->project->name }}</td></tr>
    @endif
    @if ($solicitud->justification)
        <tr><td class="k">Para qué</td><td class="v">{{ $solicitud->justification }}</td></tr>
    @endif
    <tr>
        <td class="k">Fecha de envío</td>
        <td class="v">{{ $solicitud->submitted_at?->timezone($tz)->format('d/m/Y') ?? 'sin enviar' }}</td>
    </tr>
    @if ($solicitud->budget)
        <tr><td class="k">Presupuesto</td><td class="v">{{ $solicitud->budget->name }} {{ $solicitud->budget->year }}</td></tr>
    @endif
    @if ($solicitud->approvedBy)
        <tr>
            <td class="k">Aprobó</td>
            <td class="v">{{ $solicitud->approvedBy->name }} · {{ $solicitud->decided_at?->timezone($tz)->format('d/m/Y') }}</td>
        </tr>
    @endif
</table>

{{-- El carrito ya armado va antes de las líneas y enmarcado: es lo primero
     que compras quiere copiar, y en una lista de veinte cosas se pierde. --}}
@if ($solicitud->cart_url)
    <div class="carrito">
        <div class="titulo">Carrito ya armado</div>
        <div class="url"><a href="{{ $solicitud->cart_url }}" style="color:#111">{{ $solicitud->cart_url }}</a></div>
        <div class="enlace">Trae todo lo de la lista de abajo, en las cantidades pedidas. Copiar el enlace tal cual.</div>
    </div>
@endif

<table class="lineas">
    <thead>
        <tr>
            <th style="width:2rem" class="num">#</th>
            <th>Descripción</th>
            <th class="num">Cantidad</th>
            <th>Unidad</th>
            <th class="num">Valor unitario</th>
            <th class="num">Total</th>
        </tr>
    </thead>
    <tbody>
    @foreach ($solicitud->items as $i => $linea)
        <tr>
            <td class="num">{{ $i + 1 }}</td>
            <td>
                {{ $linea->description }}
                @if ($linea->supplier)
                    <div class="enlace">Proveedor sugerido: {{ $linea->supplier }}</div>
                @endif
                @if ($linea->reference_url)
                    <div class="enlace">{{ $linea->reference_url }}</div>
                @endif
                @if ($linea->received_quantity > 0)
                    <div class="enlace">Recibido: {{ $cantidad($linea->received_quantity) }} {{ $linea->unit }}</div>
                @endif
            </td>
            <td class="num">{{ $cantidad($linea->quantity) }}</td>
            <td>{{ $linea->unit }}</td>
            <td class="num">{{ $moneda($linea->unit_price) }}</td>
            <td class="num">{{ $moneda($linea->total()) }}</td>
        </tr>
    @endforeach
    </tbody>
    <tfoot>
        <tr>
            <td colspan="5" class="num">Subtotal</td>
            <td class="num">{{ $moneda($solicitud->subtotalEnMoneda()) }}</td>
        </tr>
        @if ($iva > 0)
            <tr>
                <td colspan="5" class="num">Impuesto estimado ({{ (int) round($iva * 100) }}%)</td>
                <td class="num">{{ $moneda($solicitud->impuestoEnMoneda()) }}</td>
            </tr>
        @endif
        <tr class="total">
            <td colspan="5" class="num">Total estimado{{ $solicitud->esEnPesos() ? '' : ' en dólares' }}</td>
            <td class="num">{{ $moneda($solicitud->totalEnMoneda()) }}</td>
        </tr>
        @unless ($solicitud->esEnPesos())
            <tr class="total">
                <td colspan="5" class="num">En pesos, a {{ $pesos($solicitud->exchange_rate) }} por dólar</td>
                <td class="num">{{ $pesos($solicitud->totalEstimado()) }}</td>
            </tr>
        @endunless
    </tfoot>
</table>

@if ($solicitud->notes)
    <p style="font-size:.88rem"><strong>Observaciones.</strong> {!! nl2br(e($solicitud->notes)) !!}</p>
@endif

<table class="firmas">
    <tr>
        <td><div class="firma">Solicita · {{ $solicitud->requestedBy?->name }}</div></td>
        <td><div class="firma">Aprueba · {{ $solicitud->approvedBy?->name ?? '' }}</div></td>
    </tr>
</table>

<p class="nota">
    Los valores unitarios son estimados de referencia tomados del último costo conocido
    o de la cotización consultada; el valor definitivo lo fija el proveedor.
    Documento generado por fabOS el {{ now()->timezone($tz)->format('d/m/Y H:i') }}.
</p>

@unless ($paraPdf)
    <p class="noimprimir" style="margin-top:2rem">
        @if ($enlacePdf)
            <a class="boton principal" href="{{ $enlacePdf }}">Descargar PDF</a>
        @endif
        <button class="boton" onclick="window.print()">Imprimir</button>
    </p>
@endunless

</body>
</html>
