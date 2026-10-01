<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>QR · {{ $circuito->nombre }}</title>
    {{-- Una estación por hoja: se recorta y se pega en el lugar. --}}
    <style>
        body{font-family:system-ui,"Segoe UI",Arial,sans-serif;margin:0;color:#111;background:#fff}
        .barra{padding:1rem;display:flex;gap:1rem;align-items:center;border-bottom:1px solid #ddd}
        .hoja{page-break-after:always;break-after:page;min-height:90vh;display:flex;flex-direction:column;
              align-items:center;justify-content:center;text-align:center;padding:2rem}
        .hoja h1{font-size:2rem;margin:.6rem 0 0}
        .hoja p{margin:.3rem 0;color:#555}
        .hoja .para-pegar{margin-top:1.4rem;font-size:.85rem;color:#888;border-top:1px dashed #bbb;padding-top:.6rem}
        @media print{.barra{display:none}}
    </style>
</head>
<body>
    <div class="barra">
        <strong>{{ $circuito->nombre }}</strong> · {{ $circuito->estaciones->count() }} estaciones
        <button onclick="window.print()">Imprimir</button>
    </div>

    @foreach ($circuito->estaciones as $n => $e)
        <section class="hoja">
            <p style="letter-spacing:.15em;text-transform:uppercase;font-size:.8rem">{{ config('fabos.lab.name') }} · Recorrido</p>
            {!! $qr->svg($e->urlDelQr(), 360) !!}
            <h1>¡Lo encontraron!</h1>
            <p>Escaneen este código con el celular del equipo.</p>
            <p class="para-pegar">Estación {{ $n + 1 }} · {{ $e->nombre }}{{ $e->lugar ? ' · pegar: ' . $e->lugar : '' }}</p>
        </section>
    @endforeach
</body>
</html>
