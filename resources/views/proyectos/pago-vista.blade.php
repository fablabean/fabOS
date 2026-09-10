<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Vista previa del cobro · {{ $proyecto->code }}</title>
    <style>
        body{margin:0;background:#E8E8E2;color:#191A16;font-family:system-ui,"Segoe UI","Helvetica Neue",Arial,sans-serif;line-height:1.55}
        main{max-width:60rem;margin:0 auto;padding:1.6rem 1.4rem 4rem}
        .aviso{background:#fff7e0;border:1px solid #f0d58c;padding:.6rem .9rem;border-radius:.4rem;margin-bottom:1.2rem;font-size:.92rem}
        h1{font-size:1.3rem;margin:0 0 .2rem}
        h2{font-size:.8rem;letter-spacing:.1em;text-transform:uppercase;color:#6E7066;margin:1.8rem 0 .6rem}
        .meta{color:#6E7066;font-size:.9rem;margin:0 0 1rem}
        .correo{background:#fff;border:1px solid #C7C7BD;border-radius:6px;overflow:hidden}
        .correo .cab{padding:.8rem 1rem;border-bottom:1px solid #eee;font-size:.9rem;display:grid;grid-template-columns:6rem 1fr;gap:.2rem .8rem}
        .correo .cab b{color:#6E7066;font-weight:500}
        .correo iframe{width:100%;height:34rem;border:0;display:block;background:#fff}
        .adjunto{display:flex;gap:1rem;align-items:center;padding:.8rem 1rem;border-top:1px solid #eee;font-size:.9rem}
        .adjunto img{width:6rem;height:6rem;object-fit:contain;border:1px solid #ddd;border-radius:4px;background:#fff}
        .seccion{background:#F6F6F2;border:1px solid #C7C7BD;border-radius:6px;padding:1.2rem;max-width:40rem}
        .seccion .valor{margin:.4rem 0 .2rem;font-size:1.4rem;font-weight:700;letter-spacing:-.02em}
        .seccion .help{color:#3D4038;font-size:.92rem;margin:.2rem 0 .8rem}
        .seccion img{width:min(16rem,100%);border:1px solid #C7C7BD;border-radius:6px;background:#fff;padding:.5rem}
        .seccion label{display:block;font-family:ui-monospace,Consolas,monospace;font-size:.66rem;letter-spacing:.14em;text-transform:uppercase;color:#6E7066;margin:.9rem 0 .35rem}
        .seccion .campo{background:#E8E8E2;border:1px solid #C7C7BD;border-radius:4px;padding:.6rem .7rem;color:#93968A;font-size:.95rem}
        .seccion .boton{display:inline-block;margin-top:1rem;padding:.7rem 1.2rem;background:#0D6E63;color:#fff;border-radius:4px;font-weight:600}
    </style>
</head>
<body>
<main>
    <div class="aviso">Vista previa. Nada se ha enviado todavía: si algo no cuadra, cierra esta pestaña y corrígelo en el formulario.</div>

    <h1>Así le llegará el cobro</h1>
    <p class="meta">A {{ $vista['correo'] ?? 'sin correo de contacto' }} · {{ $proyecto->code }} · {{ $proyecto->name }}</p>

    <h2>El correo</h2>
    <div class="correo">
        <div class="cab">
            <b>Para</b><span>{{ $vista['correo'] ?? '—' }}</span>
            <b>Asunto</b><span>{{ $vista['asunto'] }}</span>
        </div>
        <iframe srcdoc="{{ $vista['html'] }}" title="El correo tal como llega"></iframe>
        @if ($qr)
            <div class="adjunto">
                <img src="{{ $qr }}" alt="QR de pago adjunto">
                <span>Adjunto: <strong>qr-de-pago</strong>, el código del banco.</span>
            </div>
        @else
            <div class="adjunto"><span>Sin QR todavía: súbelo en Finanzas → Pagos por QR o el cobro no se podrá enviar.</span></div>
        @endif
    </div>

    <h2>Y lo que verá en su proyecto, al abrir el enlace</h2>
    <div class="seccion">
        <p style="margin:0;font-weight:600">Pago</p>
        <p class="valor">{{ $valor }}@if ($concepto)<span style="font-size:.95rem;font-weight:400;color:#6E7066"> · {{ $concepto }}</span>@endif</p>
        <p class="help">{{ \App\Support\Settings::instruccionesDePago() }}</p>
        @if ($qr)<p style="margin:0 0 1rem"><img src="{{ $qr }}" alt="Código QR para pagar"></p>@endif
        <label>Captura o comprobante del pago</label><div class="campo">Elegir archivo…</div>
        <label>Nombre completo de quien pagó</label><div class="campo">{{ $proyecto->contact_name ?: 'Nombre completo' }}</div>
        <label>Número de documento</label><div class="campo">Número de documento</div>
        <span class="boton">Enviar el comprobante</span>
    </div>
</main>
</body>
</html>
