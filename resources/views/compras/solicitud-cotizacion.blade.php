@php
    /** @var \App\Models\PurchaseRequest $solicitud */
    $paraPdf = $paraPdf ?? false;
    $enlacePdf = $enlacePdf ?? null;
    $puedeFirmar = ! $paraPdf && ($puedeFirmar ?? false);
    $firma = $solicitud->firmaComoImagen();
    // Quien firma es el solicitante; si firmó otra persona del equipo, es ella.
    $firmante = $solicitud->signedBy ?? $solicitud->requestedBy;
    $tz = config('fabos.lab.timezone');
    $quien = $solicitud->requestedBy;
    $fecha = ($solicitud->submitted_at ?? now())->copy()->timezone($tz);
    $cantidad = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, ',', '.'), '0'), ',');
    // Como el formato de la Universidad: diez renglones aunque se pidan menos.
    $renglones = max(10, $solicitud->items->count());
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $solicitud->code }} · Solicitud de cotización · ADF-228-f5</title>
    <style>
        /* El ADF-228-f5 de la Universidad tal como está en su hoja de cálculo:
           mismas casillas, mismos rótulos, mismo orden. Todo en tablas, que es
           lo único que el generador de PDF arma bien; y cada tabla empieza por
           la fila que fija los anchos, o los reparte por igual. */
        .f{font-family:DejaVu Sans,Arial,Helvetica,sans-serif;font-size:{{ $paraPdf ? '8.2px' : '11.5px' }};color:#111;line-height:1.3;background:#fff}
        .f *{box-sizing:border-box}
        .f table{width:100%;border-collapse:collapse;table-layout:fixed}
        .f td{border:1px solid #111;padding:{{ $paraPdf ? '3px 4px' : '5px 6px' }};vertical-align:middle;word-wrap:break-word}
        .f .sin td{border:0}
        .f .c{text-align:center}
        .f .b{font-weight:bold}
        .f .gris{background:#d9d9d9;font-weight:bold}
        .f .titulo{background:#d9d9d9;font-weight:bold;text-align:center;letter-spacing:.03em}
        .f .sep{height:{{ $paraPdf ? '6px' : '10px' }}}
        .f .alto{height:{{ $paraPdf ? '26px' : '36px' }}}
        .f .caja{height:{{ $paraPdf ? '58px' : '80px' }};vertical-align:top}
        .f .marca{display:inline-block;width:{{ $paraPdf ? '11px' : '15px' }};height:{{ $paraPdf ? '11px' : '15px' }};border:1px solid #111;text-align:center;line-height:{{ $paraPdf ? '10px' : '14px' }};font-weight:bold;margin-left:4px}
        .f .aviso{font-style:italic;text-align:center}
        .f .esp{font-size:.92em}
        .f .firma{height:{{ $paraPdf ? '58px' : '80px' }};vertical-align:bottom;text-align:center}
        .f .firma img{max-height:{{ $paraPdf ? '50px' : '70px' }};max-width:80%}
        @if ($paraPdf)
            @page{margin:1cm 1.1cm}
            body{margin:0}
        @else
            body{margin:0;background:#e8e8e2;padding:1.2rem;font-family:system-ui,sans-serif}
            .hoja{max-width:58rem;margin:0 auto;background:#fff;padding:1.4rem;box-shadow:0 2px 14px rgba(0,0,0,.12)}
            .barra{max-width:58rem;margin:1rem auto 0;display:flex;gap:.6rem;flex-wrap:wrap}
            .barra a,.barra button{display:inline-block;padding:.6rem 1.1rem;border-radius:4px;text-decoration:none;font:inherit;font-weight:600;cursor:pointer;border:1px solid #111;background:#fff;color:#111}
            .barra .principal{background:#111;color:#fff}
            @media print{body{background:#fff;padding:0}.hoja{box-shadow:none;padding:0}.barra{display:none}}
        @endif
    </style>
</head>
<body>
<div class="hoja">
<div class="f">
    {{-- Encabezado del formato --}}
    <table>
        <tr>
            <td rowspan="3" class="c b" style="width:18%">{{ config('fabos.lab.institution') }}</td>
            <td rowspan="2" class="c b" style="font-size:1.25em">FORMATO</td>
            <td style="width:20%">Versión: 1</td>
        </tr>
        <tr>
            <td>Código: ADF-228-f5</td>
        </tr>
        <tr>
            <td class="c b">Términos de Referencia para Solicitud de Cotización</td>
            <td>Fecha: 13/Ene/2025</td>
        </tr>
    </table>

    <div class="sep"></div>

    <table><tr><td class="titulo">INFORMACION DEL SOLICITANTE</td></tr></table>

    <div class="sep"></div>

    <table>
        <tr>
            <td class="gris" style="width:14%">Fecha de Solicitud</td>
            <td class="c" style="width:6%"><span class="b">Día</span><br>{{ $fecha->format('d') }}</td>
            <td class="c" style="width:6%"><span class="b">Mes</span><br>{{ $fecha->format('m') }}</td>
            <td class="c" style="width:7%"><span class="b">Año</span><br>{{ $fecha->format('Y') }}</td>
            <td class="gris" style="width:13%">Solicitante</td>
            <td>{{ $quien?->name }}</td>
        </tr>
    </table>

    <div class="sep"></div>

    <table>
        <tr>
            <td class="gris" style="width:14%">Correo Electrónico</td>
            <td style="width:45%">{{ $quien?->email }}</td>
            <td class="gris" style="width:13%">Teléfono</td>
            <td>{{ $quien?->phone }}</td>
        </tr>
    </table>

    <div class="sep"></div>

    <table>
        <tr>
            <td class="gris" style="width:14%">Supervisor</td>
            <td style="width:31%">{{ $solicitud->supervisor }}</td>
            <td class="gris" style="width:20%">Proceso Solicitante y/o Área</td>
            <td>{{ $solicitud->process_area }}</td>
        </tr>
    </table>

    <div class="sep"></div>

    <table class="sin">
        <tr>
            <td style="width:18%;border:1px solid #111" class="gris">Requiere Arte</td>
            <td style="width:30%;padding-left:10px">
                SI <span class="marca">{{ $solicitud->requires_art ? 'X' : '' }}</span>
                &nbsp;&nbsp;&nbsp; NO <span class="marca">{{ $solicitud->requires_art ? '' : 'X' }}</span>
            </td>
            <td></td>
        </tr>
    </table>

    <div class="sep"></div>

    <table><tr><td class="aviso">«Recuerda registrar la información de forma clara y completa para agilizar el proceso.»</td></tr></table>

    <div class="sep"></div>

    <table><tr><td class="titulo">TERMINOS DE REFERENCIA</td></tr></table>
    <table style="margin-top:-1px">
        <tr class="c b gris">
            <td style="width:5%">Item</td>
            <td style="width:22%">Descripción</td>
            <td style="width:24%">Especificaciones Técnicas</td>
            <td style="width:10%">Marca</td>
            <td style="width:9%">Cantidad</td>
            <td style="width:10%">Unidad de Medida</td>
            <td>Observaciones</td>
        </tr>
        @for ($i = 0; $i < $renglones; $i++)
            @php $linea = $solicitud->items[$i] ?? null; @endphp
            <tr class="alto">
                <td class="c">{{ $i + 1 }}</td>
                <td>{{ $linea?->description }}</td>
                <td class="esp">{!! $linea ? nl2br(e((string) $linea->specs)) : '' !!}</td>
                <td class="c">{{ $linea?->brand }}</td>
                <td class="c">{{ $linea ? $cantidad($linea->quantity) : '' }}</td>
                <td class="c">{{ $linea?->unit }}</td>
                <td class="esp">{!! $linea ? nl2br(e((string) $linea->notes)) : '' !!}</td>
            </tr>
        @endfor
    </table>

    <div class="sep"></div>

    <table>
        <tr><td class="gris">Observaciones Generales <span style="font-weight:normal;font-style:italic">«Registra observaciones para información adicional y/o aclaraciones relevantes.»</span></td></tr>
        <tr>
            <td class="caja">
                @if ($solicitud->justification)<span class="b">Para qué:</span> {{ $solicitud->justification }}<br>@endif
                {!! nl2br(e((string) $solicitud->notes)) !!}
            </td>
        </tr>
    </table>

    <div class="sep"></div>
    <div class="sep"></div>

    <table>
        <tr>
            <td class="caja" style="width:50%;height:auto"><span class="b">Solicitado Por:</span> {{ $firmante?->name }}</td>
            <td class="caja" style="height:auto"></td>
        </tr>
        <tr>
            <td class="firma">
                @if ($firma)<img src="{{ $firma }}" alt="Firma de {{ $firmante?->name }}">@endif
            </td>
            <td></td>
        </tr>
        <tr>
            <td class="b">Firma del Solicitante</td>
            <td></td>
        </tr>
        <tr>
            <td><span class="b">Nombre del Solicitante:</span> {{ $firmante?->name }}</td>
            <td></td>
        </tr>
        <tr>
            <td><span class="b">Fecha:</span> {{ $fecha->format('d/m/Y') }}</td>
            <td></td>
        </tr>
    </table>

    <p style="font-size:.85em;color:#555;margin:{{ $paraPdf ? '6px' : '10px' }} 0 0">
        Solicitud {{ $solicitud->code }} · {{ config('fabos.lab.name') }} · generado por fabOS el {{ now()->timezone($tz)->format('d/m/Y H:i') }}.
    </p>
</div>
</div>

@unless ($paraPdf)
    @if (session('status'))
        <p class="barra" style="background:#d9efe9;padding:.8rem 1rem;border-radius:6px;display:block">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <p class="barra" style="background:#f3d9d9;padding:.8rem 1rem;border-radius:6px;display:block">{{ $errors->first() }}</p>
    @endif

    {{-- La firma del solicitante, dibujada aquí. Solo en el borrador y solo
         desde el panel: el enlace compartido no firma nada. --}}
    @if ($puedeFirmar)
        <form method="POST" action="{{ route('compras.firmar', $solicitud) }}" id="firmar"
              style="max-width:58rem;margin:1rem auto 0;background:#fff;padding:1.2rem 1.4rem;border-radius:6px;box-shadow:0 2px 14px rgba(0,0,0,.08)">
            @csrf
            <h2 style="margin:0 0 .3rem;font-size:1.1rem">{{ $firma ? 'Volver a firmar' : 'Firma del solicitante' }}</h2>
            <p style="margin:0 0 .7rem;color:#555;font-size:.92rem">
                Firmas como {{ auth()->user()->name }}. Dibújala con el dedo o con el mouse: es la que sale en el formato.
            </p>
            <canvas id="lienzo-firma" width="700" height="200" style="width:100%;height:auto;aspect-ratio:700/200;background:#fff;border:1px dashed #888;border-radius:6px;touch-action:none;cursor:crosshair;display:block"></canvas>
            <p style="display:flex;gap:.7rem;align-items:center;flex-wrap:wrap;margin:.6rem 0">
                <button type="button" id="borrar-firma" style="font:inherit;padding:.45rem .9rem;border:1px solid #111;background:#fff;border-radius:4px;cursor:pointer">Borrar</button>
                <span id="estado-firma" style="color:#555;font-size:.9rem">Todavía no has firmado.</span>
            </p>
            <input type="hidden" name="firma" id="campo-firma">
            <label style="display:flex;gap:.5rem;align-items:center;margin:.4rem 0 .9rem;font-size:.95rem">
                <input type="checkbox" name="enviar" value="1" checked>
                Enviarla a compras al firmar ({{ \App\Support\ConfiguracionDeCompras::nombre() }})
            </label>
            <button type="submit" style="font:inherit;font-weight:600;padding:.7rem 1.3rem;border:0;background:#111;color:#fff;border-radius:4px;cursor:pointer">Firmar</button>
        </form>

        <script>
            (function () {
                const lienzo = document.getElementById('lienzo-firma');
                const ctx = lienzo.getContext('2d');
                const campo = document.getElementById('campo-firma');
                const estado = document.getElementById('estado-firma');
                let trazando = false, hayFirma = false;

                function limpiar() {
                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(0, 0, lienzo.width, lienzo.height);
                    ctx.strokeStyle = '#111827';
                    ctx.lineWidth = 2.6;
                    ctx.lineCap = 'round';
                    ctx.lineJoin = 'round';
                    hayFirma = false;
                    campo.value = '';
                    estado.textContent = 'Todavía no has firmado.';
                    estado.style.color = '';
                }

                function punto(e) {
                    const caja = lienzo.getBoundingClientRect();
                    return { x: (e.clientX - caja.left) * (lienzo.width / caja.width), y: (e.clientY - caja.top) * (lienzo.height / caja.height) };
                }

                lienzo.addEventListener('pointerdown', function (e) {
                    trazando = true;
                    lienzo.setPointerCapture(e.pointerId);
                    const p = punto(e);
                    ctx.beginPath();
                    ctx.moveTo(p.x, p.y);
                });
                lienzo.addEventListener('pointermove', function (e) {
                    if (! trazando) return;
                    const p = punto(e);
                    ctx.lineTo(p.x, p.y);
                    ctx.stroke();
                    hayFirma = true;
                    estado.textContent = 'Firma lista.';
                });
                ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (ev) {
                    lienzo.addEventListener(ev, function () { trazando = false; });
                });

                document.getElementById('borrar-firma').addEventListener('click', limpiar);
                document.getElementById('firmar').addEventListener('submit', function (e) {
                    if (! hayFirma) {
                        e.preventDefault();
                        estado.textContent = 'Dibuja tu firma en el recuadro.';
                        estado.style.color = '#b91c1c';
                        return;
                    }
                    campo.value = lienzo.toDataURL('image/png');
                });

                limpiar();
            })();
        </script>
    @endif

    <div class="barra">
        @if ($enlacePdf)
            <a class="principal" href="{{ $enlacePdf }}">Descargar PDF</a>
        @endif
        <button type="button" onclick="window.print()">Imprimir</button>
    </div>
@endunless
</body>
</html>
