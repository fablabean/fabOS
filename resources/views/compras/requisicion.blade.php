@php
    $lab = config('fabos.lab.name');
    $simbolo = config('fabos.money.symbol');
    $iva = config('fabos.money.tax_rate');
    $tz = config('fabos.lab.timezone');
    $pesos = fn ($v) => $simbolo . number_format((float) $v, 0, ',', '.');
    $cantidad = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, ',', '.'), '0'), ',');
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $solicitud->code }} · Requisición · {{ $lab }}</title>
    <style>
        /* Pensada para imprimirse o exportarse a PDF: es lo que se le entrega
           al área de compras, así que tiene que verse bien en papel. */
        :root { --ink:#111; --soft:#666; --rule:#ddd; }
        *{box-sizing:border-box}
        body{
            font:14px/1.5 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
            color:var(--ink);margin:0;padding:2.5rem;max-width:900px;background:#fff;
        }
        header{display:flex;justify-content:space-between;align-items:flex-start;
               border-bottom:2px solid var(--ink);padding-bottom:1rem;margin-bottom:1.5rem}
        h1{font-size:1.4rem;margin:0}
        .codigo{font-size:1.6rem;font-weight:700;letter-spacing:-.02em;text-align:right}
        .meta{font-size:.85rem;color:var(--soft);text-align:right;margin-top:.2rem}
        dl{display:grid;grid-template-columns:auto 1fr;gap:.3rem 1rem;margin:0 0 1.5rem;font-size:.9rem}
        dt{color:var(--soft)}
        dd{margin:0;font-weight:500}
        table{width:100%;border-collapse:collapse;margin-bottom:1.5rem}
        th,td{text-align:left;padding:.55rem .5rem;border-bottom:1px solid var(--rule);vertical-align:top}
        th{font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:var(--soft);
           border-bottom:1px solid var(--ink)}
        td.num,th.num{text-align:right;white-space:nowrap}
        tfoot td{border-bottom:none;padding-top:.5rem}
        tfoot tr:last-child td{font-weight:700;font-size:1.05rem;border-top:2px solid var(--ink)}
        .enlace{font-size:.78rem;color:var(--soft);word-break:break-all}
        .firmas{display:grid;grid-template-columns:1fr 1fr;gap:3rem;margin-top:4rem}
        .firma{border-top:1px solid var(--ink);padding-top:.4rem;font-size:.82rem;color:var(--soft)}
        .nota{font-size:.8rem;color:var(--soft);margin-top:2rem;border-top:1px solid var(--rule);padding-top:.8rem}
        .sello{display:inline-block;border:1px solid var(--ink);border-radius:3px;
               padding:.15rem .5rem;font-size:.75rem;text-transform:uppercase;letter-spacing:.06em}
        @media print{ body{padding:0} .noimprimir{display:none} }
    </style>
</head>
<body>

<header>
    <div>
        <h1>{{ $lab }}</h1>
        <div class="meta" style="text-align:left">Requisición de compra</div>
    </div>
    <div>
        <div class="codigo">{{ $solicitud->code }}</div>
        <div class="meta">
            <span class="sello">{{ \App\Models\PurchaseRequest::ESTADOS[$solicitud->status] ?? $solicitud->status }}</span>
        </div>
    </div>
</header>

<dl>
    <dt>Solicita</dt>
    <dd>{{ $solicitud->requestedBy?->name }} · {{ $solicitud->requestedBy?->email }}</dd>

    @if ($solicitud->area)
        <dt>Área</dt>
        <dd>{{ $solicitud->area->name }}</dd>
    @endif

    @if ($solicitud->justification)
        <dt>Para qué</dt>
        <dd>{{ $solicitud->justification }}</dd>
    @endif

    <dt>Fecha de envío</dt>
    <dd>{{ $solicitud->submitted_at?->timezone($tz)->format('d/m/Y') ?? 'sin enviar' }}</dd>

    @if ($solicitud->budget)
        <dt>Presupuesto</dt>
        <dd>{{ $solicitud->budget->name }} {{ $solicitud->budget->year }}</dd>
    @endif

    @if ($solicitud->approvedBy)
        <dt>Aprobó</dt>
        <dd>{{ $solicitud->approvedBy->name }} ·
            {{ $solicitud->decided_at?->timezone($tz)->format('d/m/Y') }}</dd>
    @endif
</dl>

<table>
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
            <td class="num">{{ $pesos($linea->unit_price) }}</td>
            <td class="num">{{ $pesos($linea->total()) }}</td>
        </tr>
    @endforeach
    </tbody>
    <tfoot>
        <tr>
            <td colspan="5" class="num">Subtotal</td>
            <td class="num">{{ $pesos($solicitud->subtotal()) }}</td>
        </tr>
        <tr>
            <td colspan="5" class="num">Impuesto estimado ({{ (int) round($iva * 100) }}%)</td>
            <td class="num">{{ $pesos($solicitud->totalEstimado() - $solicitud->subtotal()) }}</td>
        </tr>
        <tr>
            <td colspan="5" class="num">Total estimado</td>
            <td class="num">{{ $pesos($solicitud->totalEstimado()) }}</td>
        </tr>
    </tfoot>
</table>

@if ($solicitud->notes)
    <p style="font-size:.88rem"><strong>Observaciones.</strong> {!! nl2br(e($solicitud->notes)) !!}</p>
@endif

<div class="firmas">
    <div class="firma">Solicita · {{ $solicitud->requestedBy?->name }}</div>
    <div class="firma">Aprueba · {{ $solicitud->approvedBy?->name ?? '' }}</div>
</div>

<p class="nota">
    Los valores unitarios son estimados de referencia tomados del último costo conocido
    o de la cotización consultada; el valor definitivo lo fija el proveedor.
    Documento generado por fabOS el {{ now()->timezone($tz)->format('d/m/Y H:i') }}.
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
