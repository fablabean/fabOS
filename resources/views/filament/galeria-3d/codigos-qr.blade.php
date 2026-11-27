{{--
    La hoja con el código QR de cada figura de la Galería 3D, para imprimir.

    Cada tarjeta se recorta y se pone junto a su pieza en el laboratorio: al
    escanearla, el teléfono abre la realidad aumentada de esa figura
    (/galeria-3d/{figura}/ra). Seis tarjetas por hoja carta o A4; el QR mide
    5 cm, que se lee bien desde un brazo de distancia.

    Página suelta, sin el marco del panel, para que imprima limpia. La abre la
    acción «Imprimir códigos QR» del listado (Comunicaciones → Galería 3D).
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Códigos QR de la Galería 3D · {{ config('fabos.lab.name') }}</title>
    <meta name="robots" content="noindex">
    @include('partials.iconos')
    <style>
        :root{--ink:#191A16;--muted:#6E7066;--rule:#C7C7BD;--accent:#0D6E63;--ground:#E8E8E2}
        *{box-sizing:border-box}
        body{margin:0;background:var(--ground);color:var(--ink);font-family:system-ui,"Segoe UI","Helvetica Neue",Arial,sans-serif;line-height:1.45}
        .barra{max-width:62rem;margin:0 auto;padding:1.5rem 1rem 1rem}
        .barra h1{margin:0 0 .3rem;font-size:1.5rem;letter-spacing:-.02em}
        .barra p{margin:.2rem 0;color:#3D4038;font-size:.95rem}
        .acciones{display:flex;gap:.6rem;flex-wrap:wrap;margin-top:1rem}
        .acciones a,.acciones button{font:inherit;font-size:.9rem;border-radius:.5rem;padding:.55rem 1rem;cursor:pointer;text-decoration:none;border:1px solid var(--rule);background:#fff;color:var(--ink)}
        .acciones .principal{background:var(--accent);border-color:var(--accent);color:#fff;font-weight:600}
        .aviso{margin-top:1rem;padding:.85rem 1rem;border-radius:.6rem;background:#fff4e0;border:1px solid #f0c27a;font-size:.92rem}
        .aviso code{background:#fff;padding:0 .3rem;border-radius:4px}

        .hoja{max-width:62rem;margin:0 auto;padding:0 1rem 3rem;display:grid;grid-template-columns:repeat(auto-fill,minmax(18rem,1fr));gap:1rem}
        .tarjeta{background:#fff;border:1px dashed #9a9c92;border-radius:.4rem;padding:1rem;display:grid;grid-template-columns:5cm 1fr;gap:1rem;align-items:center;break-inside:avoid;page-break-inside:avoid}
        .tarjeta .qr svg{width:5cm;height:5cm;display:block}
        .tarjeta .tecnica{margin:0;font-size:.62rem;font-family:ui-monospace,Consolas,monospace;letter-spacing:.14em;text-transform:uppercase;color:var(--accent)}
        .tarjeta h2{margin:.15rem 0 .4rem;font-size:1.05rem;line-height:1.2}
        .tarjeta img{width:2.6cm;height:2.6cm;object-fit:contain;display:block;margin:0 0 .3rem}
        .tarjeta .llamado{margin:0;font-size:.78rem;font-weight:700}
        .tarjeta .llamado span{font-weight:400;color:var(--muted)}
        .tarjeta .direccion{margin:.35rem 0 0;font-size:.58rem;color:var(--muted);word-break:break-all}
        .tarjeta .bajar{display:inline-block;margin-top:.4rem;font-size:.75rem;color:var(--accent)}

        @page{size:auto;margin:1cm}
        @media print{
            body{background:#fff}
            .barra,.tarjeta .bajar{display:none}
            .hoja{max-width:none;padding:0;grid-template-columns:1fr 1fr;gap:.4cm}
            /* compactas: seis por hoja carta o A4 */
            .tarjeta{border-color:#bbb;padding:.35cm;gap:.4cm;grid-template-columns:5cm 1fr}
            .tarjeta img{width:1.7cm;height:1.7cm}
            .tarjeta h2{font-size:.95rem}
        }
    </style>
</head>
<body>
    <header class="barra">
        <h1>Códigos QR de la Galería 3D</h1>
        <p>Una tarjeta por figura. Recórtala por la línea punteada y ponla junto a la pieza: al escanearla, el teléfono abre la realidad aumentada de esa figura{{ $tarjetas->contains('animado', true) ? ', con su animación en Android' : '' }}.</p>
        <p>{{ $tarjetas->count() }} figuras · los QR llevan a <strong>{{ $base }}</strong></p>

        @if ($temporal)
            <div class="aviso" role="alert">
                <strong>Estos QR son de prueba, no los imprimas todavía.</strong>
                Apuntan a <code>{{ $base }}</code>, una dirección que
                {{ str_contains($base, 'trycloudflare.com') ? 'cambia cada vez que se enciende el QR para el celular' : 'solo existe en este computador o en la red del laboratorio' }}.
                Para los definitivos, pon el dominio del sitio en el archivo <code>.env</code>
                —<code>GALERIA3D_URL_PUBLICA=https://tu-dominio</code>— o usa un <code>APP_URL</code> con https, y vuelve a abrir esta hoja.
            </div>
        @endif

        <div class="acciones">
            <button type="button" class="principal" onclick="window.print()">Imprimir</button>
            <a href="{{ \App\Filament\Resources\Galeria3d\Galeria3dResource::getUrl('index') }}">Volver al panel</a>
        </div>
    </header>

    <main class="hoja">
        @foreach ($tarjetas as $t)
            <article class="tarjeta">
                <div class="qr">{!! $t['qr'] !!}</div>
                <div>
                    <img src="{{ $t['poster'] }}" alt="">
                    <p class="tecnica">{{ $t['categoria'] }}</p>
                    <h2>{{ $t['nombre'] }}</h2>
                    <p class="llamado">Escanéame <span>y mírala en tu espacio{{ $t['animado'] ? ', en movimiento' : '' }}</span></p>
                    <p class="direccion">{{ $t['url'] }}</p>
                    <a class="bajar" href="{{ $t['descargar'] }}">Descargar este QR (SVG)</a>
                </div>
            </article>
        @endforeach
    </main>
</body>
</html>
