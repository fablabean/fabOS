@php
    /** @var \App\Models\Asset $activo */
    $tz = config('fabos.lab.timezone');
    $esquema = \App\Models\Asset::ESQUEMAS[$activo->esquema_color] ?? null;
    $accesorios = collect($activo->accesorios ?? [])->filter(fn ($a) => filled($a['concepto'] ?? null))->values();
    // El formato trae seis renglones a dos columnas: doce casillas.
    $renglones = max(6, (int) ceil($accesorios->count() / 2));
    $historialUrl = \App\Filament\Resources\WorkOrders\WorkOrderResource::getUrl('index', ['filters' => [
        'abiertas' => ['isActive' => false],
        'asset_id' => ['value' => $activo->id],
    ]]);
    $ubicacion = collect([$activo->space?->name, $activo->location?->name])->filter()->implode(' · ');
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hoja de vida · {{ $activo->name }}</title>
    <style>
        /* El formato INV-113-f1, celda por celda. */
        body{margin:0;background:#e8e8e2;padding:1.2rem;font-family:Calibri,Arial,Helvetica,sans-serif;color:#111}
        .hoja{max-width:52rem;margin:0 auto;background:#fff;padding:1.6rem 1.8rem;box-shadow:0 2px 14px rgba(0,0,0,.12)}
        table{width:100%;border-collapse:collapse;table-layout:fixed}
        td,th{border:1px solid #111;padding:4px 7px;vertical-align:middle;word-wrap:break-word;font-size:13px}
        .cab td{border-style:dashed}
        .c{text-align:center}.b{font-weight:bold}
        .titulo{font-family:'Times New Roman',serif;font-weight:bold;text-align:center;font-size:15px;background:#fff}
        .verde{color:#4e7a5b;font-weight:bold}
        .sep{height:14px}
        .etq{font-weight:bold;width:18%}
        .foto{height:15rem;text-align:center}
        .foto img{max-height:14.4rem;max-width:96%;object-fit:contain}
        .chip{display:inline-block;width:.8em;height:.8em;border-radius:50%;vertical-align:-1px;margin-right:.3em}
        .enlace{font-size:12px;word-break:break-all}
        .qr{line-height:0}
        .pie{display:flex;justify-content:space-between;color:#999;font-size:11px;margin-top:1.4rem;border-top:1px solid #ccc;padding-top:.3rem}
        .acciones{max-width:52rem;margin:0 auto .8rem;display:flex;gap:.5rem;justify-content:flex-end}
        .acciones a,.acciones button{font:inherit;font-size:14px;padding:.4rem .9rem;border-radius:6px;border:1px solid #999;background:#fff;color:#111;text-decoration:none;cursor:pointer}
        .apoyo{background:#fef9c3;border:1px solid #ca8a04;padding:.6rem .8rem;margin-bottom:.8rem;font-size:13px}
        @media print{body{background:#fff;padding:0}.hoja{box-shadow:none;padding:0}.acciones{display:none}@page{margin:1.2cm}}
        @media (max-width:640px){body{padding:.5rem}.hoja{padding:.8rem}td{font-size:11px;padding:3px 4px}}
    </style>
</head>
<body>
<div class="acciones">
    <a href="{{ \App\Filament\Resources\Assets\AssetResource::getUrl('edit', ['record' => $activo]) }}">Editar la ficha</a>
    <button type="button" onclick="window.print()">Imprimir</button>
</div>
<div class="hoja">
    @unless ($activo->llevaHojaDeVida())
        <div class="apoyo">Este equipo está marcado como <b>equipo de apoyo</b> (Anexo 2 del INV-118) o es de cómputo: según el procedimiento no lleva hoja de vida.</div>
    @endunless

    <table class="cab">
        <tr>
            <td rowspan="2" class="c b" style="width:25%;font-size:18px">{{ config('fabos.lab.institution') }}</td>
            <td rowspan="2" class="c b" style="font-size:15px">FORMATO</td>
            <td style="width:24%"><b>Versión:</b> 0</td>
        </tr>
        <tr><td><b>Código:</b> INV-113-f1</td></tr>
        <tr>
            <td colspan="2" class="c verde" style="font-size:15px">HOJA DE VIDA EQUIPOS Y HERRAMIENTAS</td>
            <td><b>Fecha:</b> 26/May/2023</td>
        </tr>
    </table>

    <div class="sep"></div>

    <table>
        <tr>
            <td class="etq">Equipo o Herramienta</td>
            <td colspan="2" class="b">{{ $activo->name }}</td>
            <td class="etq">Marca:</td>
            <td colspan="2">{{ $activo->brand }}</td>
        </tr>
        <tr>
            <td class="etq">Código EAN:</td>
            <td colspan="2">{{ $activo->asset_tag ?: '(activo ' . $activo->numero() . ')' }}</td>
            <td class="etq">Serial:</td>
            <td colspan="2">{{ $activo->serial }}</td>
        </tr>
        <tr>
            <td class="etq">Ubicación:</td>
            <td colspan="2">{{ $ubicacion }}</td>
            <td class="etq">Modelo:</td>
            <td colspan="2">{{ $activo->model }}</td>
        </tr>
        {{-- El renglón en blanco del formato: va la categoría del INV-118. --}}
        <tr>
            <td colspan="6" style="height:2.6rem">
                <b>Categoría (INV-118):</b>
                @if ($esquema)
                    <span class="chip" style="background:{{ $esquema['color'] }}"></span>{{ $esquema['nombre'] }}
                @else
                    sin asignar
                @endif
                &nbsp;&nbsp;·&nbsp;&nbsp;<b>Frecuencia revisión rutinaria:</b> {{ $frecuencia ?? 'sin franja en la pauta preventiva' }}
                &nbsp;&nbsp;·&nbsp;&nbsp;<b>Área:</b> {{ $activo->area?->name }}
            </td>
        </tr>
        <tr><td colspan="6" class="titulo">REGISTRO FOTOGRÁFICO</td></tr>
        <tr>
            <td colspan="6" class="foto">
                @if ($foto)
                    <img src="{{ $foto }}" alt="{{ $activo->name }}">
                @endif
            </td>
        </tr>
        <tr><td colspan="6" class="titulo">Accesorios o Piezas Adicionales</td></tr>
        <tr>
            <th colspan="2">Concepto</th><th>Cant.</th>
            <th colspan="2">Concepto</th><th>Cant.</th>
        </tr>
        @for ($i = 0; $i < $renglones; $i++)
            @php $izq = $accesorios[$i] ?? null; $der = $accesorios[$i + $renglones] ?? null; @endphp
            <tr>
                <td colspan="2">{{ $izq['concepto'] ?? '' }}&nbsp;</td><td class="c">{{ $izq['cantidad'] ?? '' }}</td>
                <td colspan="2">{{ $der['concepto'] ?? '' }}</td><td class="c">{{ $der['cantidad'] ?? '' }}</td>
            </tr>
        @endfor
        <tr>
            <td class="b">Observaciones</td>
            <td colspan="5" style="height:4.5rem;vertical-align:top;white-space:pre-line">{{ $activo->observaciones }}</td>
        </tr>
        <tr><td colspan="6" class="b" style="font-size:14px;border-bottom:0;padding-top:.8rem">Mantenimientos Preventivos y/o Correctivos</td></tr>
        <tr><th colspan="5">Descripción</th><th>Fecha</th></tr>
        {{-- En vez de copiar orden por orden: el enlace al historial vivo. --}}
        <tr>
            <td colspan="5">
                <table style="table-layout:auto"><tr>
                    <td style="border:0;padding:0 .6rem 0 0;width:1%" class="qr">{!! app(\App\Services\Qr\QrRenderer::class)->svg($historialUrl, 92) !!}</td>
                    <td style="border:0;padding:0">
                        <b>Historial completo de mantenimientos en FabOS</b> (órdenes de trabajo de este equipo):<br>
                        <a class="enlace" href="{{ $historialUrl }}">{{ config('app.url') }}/admin/work-orders · equipo {{ $activo->numero() }}</a> (o escanear el QR)<br>
                        <span style="font-size:12px">
                            {{ $preventivos }} preventivos y {{ $correctivos }} correctivos cerrados
                            @if ($abiertas) · {{ $abiertas }} {{ $abiertas === 1 ? 'orden abierta' : 'órdenes abiertas' }} @endif
                            · cada orden guarda la lista de chequeo, el trabajo hecho y sus fotos.
                        </span>
                    </td>
                </tr></table>
            </td>
            <td class="c" style="font-size:12px">
                @if ($ultima)
                    Último:<br>{{ $ultima->closed_at?->timezone($tz)->format('d/m/Y') }}
                @else
                    —
                @endif
            </td>
        </tr>
    </table>

    <div class="pie">
        <span>DOCUMENTO ORIGINAL – INV-113-f1 · generado por FabOS el {{ now($tz)->format('d/m/Y H:i') }}</span>
        <span>Pág. 1 de 1</span>
    </div>
</div>
</body>
</html>
