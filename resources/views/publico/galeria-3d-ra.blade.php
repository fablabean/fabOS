{{--
    La experiencia de realidad aumentada de una figura: a donde lleva su QR.

    Pensada para el teléfono que acaba de escanear el código junto a la pieza:
    sin menú ni colección, la figura girando con su animación y un solo botón
    grande. Los navegadores no dejan abrir la cámara sin que la persona toque
    algo, así que el botón es lo primero que se ve.

    iPhone/iPad → Vista rápida de Apple (.usdz); Android → Scene Viewer de
    Google (-ar.glb, con su animación); computador → el QR para seguir en el
    teléfono. Todo en public/3d/ra.js.
--}}
@php
    $vendor = asset('3d/vendor/three-r186');
    $datos = [
        'slug'       => $m['slug'],
        'nombre'     => $m['nombre'],
        'urls'       => $m['urls'],
        'animado'    => $m['animado'],
        'escalaFija' => $m['escala_fija'],
        'qr'         => $qr,
        'qrAlcanzable' => $qrAlcanzable,
    ];
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $m['nombre'] }} en realidad aumentada · {{ config('fabos.lab.name') }}</title>
    <meta name="description" content="{{ $m['resumen'] }}">
    <meta name="robots" content="noindex">
    <link rel="canonical" href="{{ route('galeria.show', $m['slug']) }}">
    {{-- Todo lo que la página va a pedir, pedido desde ya y a la vez: sin esto
         el navegador lo descubre de a uno (la página, luego ra.js, luego
         three.js, luego el modelo) y por el enlace del celular cada paso
         suma una ida y vuelta. --}}
    {{-- El mapa de importación va ANTES de las precargas: si una precarga
         empieza primero, el navegador puede ignorar el mapa y fallar con
         «Failed to resolve module specifier "three"» (pasaba a veces). --}}
    <script type="importmap">
    {
        "imports": {
            "three": "{{ $vendor }}/three.module.min.js",
            "three/addons/": "{{ $vendor }}/addons/",
            "fabos/capacidad": "{{ asset('3d/capacidad.js') }}?v={{ filemtime(public_path('3d/capacidad.js')) }}"
        }
    }
    </script>
    <link rel="modulepreload" href="{{ asset('3d/ra.js') }}?v={{ filemtime(public_path('3d/ra.js')) }}">
    <link rel="modulepreload" href="{{ $vendor }}/three.module.min.js">
    <link rel="modulepreload" href="{{ $vendor }}/three.core.min.js">
    <link rel="modulepreload" href="{{ asset('3d/capacidad.js') }}?v={{ filemtime(public_path('3d/capacidad.js')) }}">
    <link rel="modulepreload" href="{{ $vendor }}/addons/loaders/GLTFLoader.js">
    <link rel="modulepreload" href="{{ $vendor }}/addons/utils/BufferGeometryUtils.js">
    <link rel="modulepreload" href="{{ $vendor }}/addons/utils/SkeletonUtils.js">
    <link rel="modulepreload" href="{{ $vendor }}/addons/controls/OrbitControls.js">
    <link rel="modulepreload" href="{{ $vendor }}/addons/environments/RoomEnvironment.js">
    <link rel="modulepreload" href="{{ $vendor }}/addons/libs/meshopt_decoder.module.js">
    <link rel="preload" href="{{ $m['urls']['glb'] }}" as="fetch" crossorigin>
    <meta property="og:title" content="{{ $m['nombre'] }} · Galería 3D">
    <meta property="og:image" content="{{ $m['urls']['poster'] }}">
    @include('partials.iconos')
    @include('partials.tema')
    <style>
        :root{
            --ground:#E8E8E2; --surface:#F6F6F2; --ink:#191A16; --ink-soft:#3D4038;
            --muted:#6E7066; --rule:#C7C7BD; --accent:#0D6E63; --accent-ink:#ffffff;
        }
        @media (prefers-color-scheme:dark){
            :root:not([data-theme="light"]){
                --ground:#131511; --surface:#1B1E19; --ink:#E9EAE2; --ink-soft:#C6C8BC;
                --muted:#93968A; --rule:#2F342B; --accent:#5CC9B8; --accent-ink:#0b1513;
            }
        }
        :root[data-theme="dark"]{
            --ground:#131511; --surface:#1B1E19; --ink:#E9EAE2; --ink-soft:#C6C8BC;
            --muted:#93968A; --rule:#2F342B; --accent:#5CC9B8; --accent-ink:#0b1513;
        }
        *{box-sizing:border-box}
        html,body{height:100%}
        body{margin:0;background:var(--ground);color:var(--ink);font-family:system-ui,"Segoe UI","Helvetica Neue",Arial,sans-serif;line-height:1.5;
             display:flex;flex-direction:column;min-height:100dvh}
        a{color:var(--accent)}
        .ra-arriba{display:flex;justify-content:space-between;align-items:center;gap:1rem;padding:.9rem 1rem .2rem;padding-top:max(.9rem,env(safe-area-inset-top))}
        .ra-arriba .lab{font-size:.72rem;font-family:ui-monospace,Consolas,monospace;letter-spacing:.16em;text-transform:uppercase;color:var(--muted);text-decoration:none}
        .ra-arriba .lab b{color:var(--accent);font-weight:700}

        /* el escenario: la figura girando; la foto mientras carga */
        .ra-escena{position:relative;flex:1 1 auto;min-height:44vh}
        .ra-escena img,.ra-escena canvas{position:absolute;inset:0;width:100%;height:100%;object-fit:contain;display:block}
        .ra-escena img{padding:1.2rem;transition:opacity .4s}
        .ra-escena.listo img{opacity:0}
        .ra-escena canvas{touch-action:none}
        .ra-escena .pista{position:absolute;left:50%;bottom:.4rem;transform:translateX(-50%);font-size:.75rem;color:var(--muted);white-space:nowrap;opacity:0;transition:opacity .4s}
        .ra-escena.listo .pista{opacity:1}

        .ra-tarjeta{background:var(--surface);border-top:1px solid var(--rule);border-radius:1.25rem 1.25rem 0 0;
            padding:1.2rem 1.2rem max(1.2rem,env(safe-area-inset-bottom));box-shadow:0 -8px 30px rgba(0,0,0,.06)}
        .ra-tarjeta .tecnica{margin:0;font-size:.72rem;font-family:ui-monospace,Consolas,monospace;letter-spacing:.14em;text-transform:uppercase;color:var(--accent)}
        .ra-tarjeta h1{margin:.2rem 0 .35rem;font-size:1.55rem;line-height:1.15;letter-spacing:-.02em}
        .ra-tarjeta .resumen{margin:0 0 1rem;color:var(--ink-soft);font-size:.95rem}

        .ra-boton{display:flex;align-items:center;justify-content:center;gap:.6rem;width:100%;border:0;border-radius:999px;
            background:var(--accent);color:var(--accent-ink);font:inherit;font-size:1.08rem;font-weight:700;padding:1rem 1.2rem;cursor:pointer;
            box-shadow:0 6px 20px color-mix(in srgb,var(--accent) 35%,transparent)}
        .ra-boton svg{width:1.4rem;height:1.4rem}
        .ra-boton:focus-visible{outline:3px solid var(--ink);outline-offset:3px}
        @media (prefers-reduced-motion:no-preference){
            .ra-boton{animation:latir 2.2s ease-in-out 3}
            @keyframes latir{0%,100%{transform:scale(1)}50%{transform:scale(1.035)}}
        }
        .ra-boton[hidden]{display:none}
        .ra-ayuda{margin:.7rem 0 0;font-size:.82rem;color:var(--muted);text-align:center}
        .ra-aviso{margin:0 0 1rem;padding:.75rem .9rem;border-radius:.75rem;background:color-mix(in srgb,#d97706 14%,var(--surface));font-size:.88rem}
        .ra-aviso[hidden]{display:none}
        .ra-aviso-suave{background:color-mix(in srgb,var(--accent) 10%,var(--surface))}
        .ra-android{margin:1rem 0 0;padding:.9rem 1rem;border-radius:.9rem;border:1px solid color-mix(in srgb,#d97706 40%,var(--rule));background:color-mix(in srgb,#d97706 9%,var(--surface))}
        .ra-android[hidden]{display:none}
        .ra-android strong{display:block;margin-bottom:.2rem}
        .ra-android p{margin:0 0 .7rem;font-size:.9rem;color:var(--ink-soft)}
        .ra-android-acciones{display:flex;flex-direction:column;gap:.5rem}

        /* Aviso de compatibilidad */
        /* Aviso de permisos: la RA no inició porque falta un permiso del celular */
        .ra-permisos{margin:0 0 .9rem;padding:.75rem .9rem;border-radius:.8rem;border:1px solid color-mix(in srgb,#d97706 45%,var(--rule));background:color-mix(in srgb,#d97706 12%,var(--surface))}
        .ra-permisos[hidden]{display:none}
        .ra-permisos strong{display:block;margin-bottom:.25rem}
        .ra-permisos p{margin:0 0 .45rem;font-size:.88rem}
        .ra-permisos ol{margin:0;padding-left:1.2rem;font-size:.86rem}
        .ra-compat .ra-permisos li{display:list-item;margin:.25rem 0;padding:0;border:0;border-radius:0;line-height:1.4}
.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
        .ra-compat-enlace{display:block;margin:.55rem auto 0;padding:.2rem .4rem;border:0;background:none;color:var(--accent);font:inherit;font-size:.82rem;text-decoration:underline;text-underline-offset:3px;cursor:pointer}
        .ra-compat-enlace[hidden]{display:none}
        .ra-compat-fondo{position:fixed;inset:0;z-index:60;background:rgba(0,0,0,.45);display:flex;align-items:flex-end;justify-content:center}
        .ra-compat-fondo[hidden]{display:none}
        .ra-compat{width:min(34rem,100%);max-height:88vh;overflow:auto;background:var(--surface);color:var(--ink);border-radius:1.25rem 1.25rem 0 0;padding:1.2rem 1.1rem calc(1.1rem + env(safe-area-inset-bottom));box-shadow:0 -10px 40px rgba(0,0,0,.25)}
        @media (min-width:40rem){ .ra-compat-fondo{align-items:center} .ra-compat{border-radius:1.25rem} }
        .ra-compat h2{margin:0 0 .3rem;font-size:1.15rem;line-height:1.25}
        .ra-compat .resumen{margin:0 0 .9rem;font-size:.92rem;color:var(--ink-soft)}
        .ra-compat .etiqueta{display:inline-block;margin:0 0 .6rem;padding:.2rem .6rem;border-radius:999px;font-size:.72rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase}
        .ra-compat .etiqueta.completa{background:color-mix(in srgb,#16a34a 16%,var(--surface));color:#15803d}
        .ra-compat .etiqueta.camara{background:color-mix(in srgb,#d97706 18%,var(--surface));color:#b45309}
        .ra-compat .etiqueta.ninguna{background:color-mix(in srgb,#dc2626 15%,var(--surface));color:#b91c1c}
        .ra-compat ul{list-style:none;margin:0 0 1rem;padding:0;display:grid;gap:.45rem}
        .ra-compat li{display:grid;grid-template-columns:1.6rem 1fr;gap:.55rem;align-items:start;padding:.55rem .65rem;border:1px solid var(--rule);border-radius:.7rem}
        .ra-compat li .icono{width:1.6rem;height:1.6rem;border-radius:50%;display:grid;place-items:center;font-weight:800;font-size:.85rem;color:#fff}
        .ra-compat li.ok .icono{background:#16a34a} .ra-compat li.no .icono{background:#dc2626} .ra-compat li.aviso .icono{background:#d97706} .ra-compat li.duda .icono{background:#6b7280}
        .ra-compat li strong{display:block;font-size:.88rem}
        .ra-compat li span{display:block;font-size:.82rem;color:var(--ink-soft);line-height:1.4}
        .ra-compat details{margin:0 0 1rem;border:1px solid var(--rule);border-radius:.7rem;padding:.6rem .75rem}
        .ra-compat summary{cursor:pointer;font-weight:600;font-size:.9rem}
        .ra-compat details dl{margin:.6rem 0 0;display:grid;gap:.55rem;font-size:.84rem}
        .ra-compat details dt{font-weight:700}
        .ra-compat details dd{margin:.1rem 0 0;color:var(--ink-soft);line-height:1.45}
        .ra-compat .acciones{display:flex;flex-direction:column;gap:.5rem}
        .ra-compat .acciones button,.ra-compat .acciones a{display:block;width:100%;text-align:center;border-radius:999px;padding:.7rem 1rem;font:inherit;font-weight:600;font-size:.93rem;border:1px solid var(--accent);text-decoration:none;cursor:pointer;box-sizing:border-box}
        .ra-compat .acciones .principal{background:var(--accent);color:var(--accent-ink)}
        .ra-compat .acciones .secundario{background:transparent;color:var(--accent)}
        .ra-compat .acciones [hidden]{display:none}
        .ra-android-acciones a{display:block;text-align:center;text-decoration:none;border:1px solid var(--rule);border-radius:999px;padding:.6rem 1rem;font-weight:600;font-size:.92rem;color:var(--ink);background:var(--surface)}
        .ra-android-acciones a.principal{background:var(--accent);border-color:var(--accent);color:var(--accent-ink)}
        .ra-android-acciones a[hidden]{display:none}

        /* sobre la cámara (WebXR) */
        .ra-xr{position:fixed;inset:0;z-index:50;color:#fff;font-family:inherit;pointer-events:none}
        .ra-xr[hidden]{display:none}
        .ra-xr .zona{position:absolute;inset:0;pointer-events:auto;touch-action:none}
        .ra-xr .mensaje{position:absolute;left:50%;top:max(1.2rem,env(safe-area-inset-top));transform:translateX(-50%);margin:0;background:rgba(0,0,0,.55);padding:.55rem 1rem;border-radius:999px;font-size:.92rem;white-space:nowrap;max-width:92vw;overflow:hidden;text-overflow:ellipsis}
        .ra-xr .acciones{position:absolute;left:0;right:0;bottom:max(1.4rem,env(safe-area-inset-bottom));display:flex;justify-content:center;gap:.6rem;pointer-events:auto}
        .ra-xr button{font:inherit;font-weight:600;border:0;border-radius:999px;padding:.75rem 1.2rem;background:rgba(0,0,0,.6);color:#fff}
        .ra-xr button.principal{background:#fff;color:#111}
        .ra-xr button[hidden]{display:none}
        /* modo cámara: Android sin ARCore (la figura sobre la imagen de la cámara) */
        .ra-cam{position:fixed;inset:0;z-index:50;background:#000;color:#fff;overflow:hidden}
        .ra-cam[hidden]{display:none}
        .ra-cam video{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
        .ra-cam canvas{position:absolute;inset:0;width:100%!important;height:100%!important;touch-action:none}
        .ra-cam .mensaje{position:absolute;left:50%;top:max(1.2rem,env(safe-area-inset-top));transform:translateX(-50%);margin:0;background:rgba(0,0,0,.55);padding:.55rem 1rem;border-radius:999px;font-size:.88rem;text-align:center;width:max-content;max-width:92vw;pointer-events:none}
        .ra-cam .acciones{position:absolute;left:0;right:0;bottom:max(1.4rem,env(safe-area-inset-bottom));display:flex;justify-content:center;gap:.6rem}
        .ra-cam button{font:inherit;font-weight:600;border:0;border-radius:999px;padding:.75rem 1.2rem;background:rgba(0,0,0,.6);color:#fff}
        .ra-cam button.principal{background:#fff;color:#111}
        .ra-cam .permiso{position:absolute;left:50%;top:calc(max(1.2rem,env(safe-area-inset-top)) + 3.2rem);transform:translateX(-50%);margin:0;width:min(26rem,92vw);background:rgba(180,83,9,.94);color:#fff;padding:.7rem .9rem;border-radius:.9rem;font-size:.86rem;line-height:1.4}
        .ra-cam .permiso[hidden]{display:none}
        .ra-android-acciones button{font:inherit;display:block;width:100%;text-align:center;border:1px solid var(--accent);border-radius:999px;padding:.6rem 1rem;font-weight:600;font-size:.92rem;background:var(--accent);color:var(--accent-ink);cursor:pointer}
        .ra-android-acciones button[hidden]{display:none}
        .ra-qr{display:flex;gap:1rem;align-items:center}
        .ra-qr[hidden]{display:none}
        .ra-qr img{width:7.5rem;height:7.5rem;background:#fff;border-radius:.5rem;padding:.3rem;border:1px solid var(--rule);flex:none}
        .ra-qr p{margin:0;font-size:.9rem;color:var(--ink-soft)}
        .ra-enlaces{display:flex;justify-content:center;gap:1.2rem;flex-wrap:wrap;margin-top:.9rem;font-size:.88rem}
        @media (min-width:56rem){
            body{flex-direction:row;flex-wrap:wrap}
            .ra-arriba{width:100%}
            .ra-escena{flex:1 1 55%;min-height:80vh}
            .ra-tarjeta{flex:0 0 26rem;align-self:center;border-radius:1.25rem;border:1px solid var(--rule);margin:2rem}
        }
    </style>
</head>
<body>
    <header class="ra-arriba">
        <a class="lab" href="{{ route('galeria.index') }}">{{ config('fabos.lab.name') }} · <b>Galería 3D</b></a>
    </header>

    <div class="ra-escena" id="ra-escena">
        <img src="{{ $m['urls']['poster'] }}" alt="{{ $m['nombre'] }}" width="800" height="800">
        <canvas id="ra-lienzo" aria-label="{{ $m['nombre'] }} en 3D. Arrastra para girarlo."></canvas>
        <span class="pista">Arrastra para girarlo</span>
    </div>

    <main class="ra-tarjeta">
        <p class="tecnica">{{ $m['tecnica'] ?: $m['categoria_nombre'] }}</p>
        <h1>{{ $m['nombre'] }}</h1>
        <p class="resumen">{{ $m['resumen'] }}</p>

        <p class="ra-aviso" id="ra-aviso" role="status" hidden></p>

        {{-- Android dentro del navegador de otra app (lector de QR, Lens,
             redes sociales): casi nunca deja abrir la realidad aumentada. --}}
        <p class="ra-aviso ra-aviso-suave" id="ra-chrome-aviso" hidden>
            Estás viendo esta página dentro de otra app. Para la realidad aumentada, <a id="ra-chrome" href="#">ábrela en Chrome</a>.
        </p>
        {{-- iPhone dentro de otra app (Google, WhatsApp, Instagram…): iOS no deja abrir la Vista rápida --}}
        <p class="ra-aviso ra-aviso-suave" id="ra-safari-aviso" hidden>
            Esta página se abrió dentro de otra app, donde el iPhone no abre su realidad aumentada. Puedes verla con la cámara aquí mismo o, para la completa, <a id="ra-safari" href="#">ábrela en Safari</a> (o toca ··· y «Abrir en Safari»).
        </p>

        <button type="button" class="ra-boton" id="ra-boton">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2.5 3.5 7v10L12 21.5 20.5 17V7L12 2.5Z"/><path d="M3.5 7 12 11.5 20.5 7M12 11.5v10"/></svg>
            Verlo en tu espacio
        </button>
        <p class="ra-ayuda" id="ra-ayuda">Apunta la cámara al piso o a una mesa.{{ $m['animado'] ? ' En Android se ve con su animación.' : '' }}</p>
        <button type="button" class="ra-compat-enlace" id="ra-compat-abrir" hidden>¿Mi celular es compatible?</button>

        {{-- Android: si la realidad aumentada no se abrió, qué hacer. --}}
        <div class="ra-android" id="ra-android" role="alert" hidden>
            <strong id="ra-android-titulo">No se abrió la realidad aumentada</strong>
            <p id="ra-android-texto"></p>
            <div class="ra-android-acciones">
                <button type="button" id="ra-android-camara">Verla con la cámara</button>
                <a id="ra-android-chrome" href="#">Abrir en Chrome</a>
                <a href="https://play.google.com/store/apps/details?id=com.google.ar.core" target="_blank" rel="noopener">Instalar los servicios de RA</a>
                <a id="ra-android-escena" href="#">Probar con la app de Google</a>
            </div>
        </div>

        {{-- En un computador no hay cámara que apunte a la mesa: se sigue en el teléfono. --}}
        <div class="ra-qr" id="ra-qr" hidden>
            <img src="" alt="Código QR para abrir esta figura en el teléfono" width="240" height="240">
            <p><strong>Ábrelo en el teléfono.</strong><br>Escanea el código con la cámara y toca «Verlo en tu espacio». Funciona en Android con ARCore y en iPhone o iPad.</p>
        </div>

        <nav class="ra-enlaces">
            <a href="{{ route('galeria.show', $m['slug']) }}">Ficha completa y descargas</a>
            <a href="{{ route('galeria.index') }}">Más figuras</a>
        </nav>
    </main>

    {{-- Lo que se ve sobre la cámara en la realidad aumentada de Android (WebXR). --}}
    <div class="ra-xr" id="ra-xr" hidden>
        <div class="zona" id="ra-xr-zona"></div>
        <p class="mensaje" id="ra-xr-mensaje">Mueve el teléfono despacio sobre el piso o una mesa</p>
        <div class="acciones">
            <button type="button" id="ra-xr-otra-vez" hidden>Ponerlo en otro lugar</button>
            <button type="button" class="principal" id="ra-xr-salir">Salir</button>
        </div>
    </div>

    {{-- Modo cámara: en un Android sin ARCore (sin los Servicios de Google Play
         para RA) no hay realidad aumentada con seguimiento; la figura se pone
         sobre la imagen de la cámara y se acomoda con los dedos. --}}
    <div class="ra-cam" id="ra-cam" hidden>
        <video id="ra-cam-video" playsinline muted autoplay></video>
        <p class="mensaje" id="ra-cam-mensaje">Un dedo: girar · dos dedos: mover y cambiar el tamaño</p>
        <p class="permiso" id="ra-cam-permiso" role="alert" hidden></p>
        <div class="acciones">
            <button type="button" id="ra-cam-centrar" hidden>Centrar</button>
            <button type="button" id="ra-cam-foto">Tomar foto</button>
            <button type="button" class="principal" id="ra-cam-salir">Salir</button>
        </div>
    </div>

    {{-- Aviso de compatibilidad: qué revisó la página en este celular y qué se necesita (ra.js → revisarCompatibilidad) --}}
    <div class="ra-compat-fondo" id="ra-compat-fondo" hidden>
        <div class="ra-compat" role="dialog" aria-modal="true" aria-labelledby="ra-compat-titulo" tabindex="-1" id="ra-compat">
            <span class="etiqueta" id="ra-compat-etiqueta"></span>
            <h2 id="ra-compat-titulo">Compatibilidad de tu celular</h2>
            <p class="resumen" id="ra-compat-resumen"></p>
            {{-- Si la realidad aumentada no inició: qué permiso activar y cómo (ra.js → mostrarAvisoPermisos) --}}
            <div class="ra-permisos" id="ra-compat-permisos" role="alert" hidden>
                <strong>⚠ Habilita los permisos del celular</strong>
                <p id="ra-compat-permisos-texto"></p>
                <ol id="ra-compat-permisos-pasos"></ol>
            </div>
            <ul id="ra-compat-lista" aria-label="Lo que se revisó en este celular"></ul>
            <details>
                <summary>Requisitos para la realidad aumentada</summary>
                <dl>
                    <dt>iPhone o iPad</dt>
                    <dd>iOS 12 o más reciente, en un iPhone 6s / SE o más nuevo (procesador A9 en adelante, con ARKit). Ábrelo en Safari; también funciona en Chrome para iPhone.</dd>
                    <dt>Android</dt>
                    <dd>Android 7.0 o más reciente, con los «Servicios de Google Play para RA» (ARCore), que Google da solo a los modelos que certifica. Navegador: Chrome 81 o más reciente (o Samsung Internet).</dd>
                    <dt>Sin ARCore (modo cámara)</dt>
                    <dd>Android 7.0 o más reciente con Chrome, o iPhone con iOS 14.3 o más reciente: la figura se ve sobre la imagen de la cámara, pero no queda fija al piso.</dd>
                    <dt>Cámara, sensores y gráficos</dt>
                    <dd>Cámara trasera; giroscopio y acelerómetro (para seguir el movimiento del celular); gráficos 3D (WebGL), que tienen casi todos los celulares de los últimos años.</dd>
                    <dt>Permisos</dt>
                    <dd>Permitir el uso de la cámara a esta página. En iPhone, en el modo cámara, también «Movimiento y orientación».</dd>
                    <dt>Conexión</dt>
                    <dd>La página debe abrirse con https (el QR de la galería ya lo hace) y con internet para bajar la figura.</dd>
                </dl>
            </details>
            <div class="acciones">
                <button type="button" class="principal" id="ra-compat-camara" hidden>Verla con la cámara</button>
                <a class="secundario" id="ra-compat-navegador" href="#" hidden></a>
                <a class="secundario" id="ra-compat-arcore" href="https://play.google.com/store/apps/details?id=com.google.ar.core" target="_blank" rel="noopener" hidden>Instalar los Servicios de Google Play para RA</a>
                <button type="button" class="secundario" id="ra-compat-cerrar">Cerrar</button>
            </div>
        </div>
    </div>

    <script type="application/json" id="ra-datos">@json($datos)</script>
    <script type="module" src="{{ asset('3d/ra.js') }}?v={{ filemtime(public_path('3d/ra.js')) }}"></script>
</body>
</html>
