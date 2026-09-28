<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>QR de asistencia · {{ $edicion->nombre() }}</title>
    {{-- Una hoja sola, sin la barra del sitio: se imprime o se proyecta en la
         puerta, y lo único que tiene que verse es el código. --}}
    <style>
        body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;color:#111;background:#fff;
             display:flex;min-height:100vh;align-items:center;justify-content:center;text-align:center}
        main{padding:2rem;max-width:40rem}
        h1{font-size:2rem;margin:.2rem 0}
        .rotulo{text-transform:uppercase;letter-spacing:.14em;font-size:.8rem;color:#555;margin:0}
        .sesion{font-size:1.1rem;color:#333;margin:.3rem 0 1.4rem}
        .qr svg{width:min(80vw,26rem);height:auto}
        .paso{font-size:1.15rem;margin-top:1.2rem}
        .url{font-family:ui-monospace,Consolas,monospace;font-size:.8rem;color:#555;word-break:break-all;margin-top:.8rem}
        .imprimir{margin-top:1.4rem}
        @media print{.imprimir{display:none}}
    </style>
</head>
<body>
<main>
    <p class="rotulo">{{ config('fabos.lab.name') }} · Registro de asistencia</p>
    <h1>{{ $edicion->nombre() }}</h1>
    <p class="sesion">{{ $sesion->nombre() }}</p>

    <div class="qr">{!! $qr !!}</div>

    <p class="paso">Escanéalo con la cámara del teléfono y escribe el correo con que te inscribiste.</p>
    <p class="url">{{ $sesion->url() }}</p>

    <p class="imprimir"><button onclick="window.print()">Imprimir</button></p>
</main>
</body>
</html>
