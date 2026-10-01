<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title', 'Recorrido') · {{ config('fabos.lab.name') }}</title>
    @include('partials.iconos')
    @include('partials.tema')
    {{-- Pantallas de juego: se leen de pie, caminando y con prisa. Letra grande,
         botones grandes, nada que distraiga. --}}
    <style>
        :root{
            --ground:#E8E8E2; --surface:#F6F6F2; --ink:#191A16; --ink-soft:#3D4038;
            --muted:#6E7066; --rule:#C7C7BD; --accent:#0D6E63; --danger:#B42318; --ok:#1E7D45;
        }
        @media (prefers-color-scheme:dark){
            :root:not([data-theme="light"]){
                --ground:#131511; --surface:#1B1E19; --ink:#E9EAE2; --ink-soft:#C6C8BC;
                --muted:#93968A; --rule:#2F342B; --accent:#5CC9B8; --danger:#F07D6E; --ok:#5DD39E;
            }
        }
        :root[data-theme="dark"]{
            --ground:#131511; --surface:#1B1E19; --ink:#E9EAE2; --ink-soft:#C6C8BC;
            --muted:#93968A; --rule:#2F342B; --accent:#5CC9B8; --danger:#F07D6E; --ok:#5DD39E;
        }
        *{box-sizing:border-box}
        body{margin:0;background:var(--ground);color:var(--ink);
             font-family:system-ui,"Segoe UI","Helvetica Neue",Arial,sans-serif;line-height:1.5}
        main{max-width:34rem;margin:0 auto;padding:1.2rem 1rem 3rem}
        main.ancho{max-width:none;padding:1.5rem 2rem}
        a{color:var(--accent)}
        h1{font-size:1.5rem;margin:.2rem 0 .8rem;line-height:1.2}
        h2{font-size:1.15rem;margin:0 0 .6rem}
        .marca{font-size:.75rem;letter-spacing:.12em;text-transform:uppercase;color:var(--muted)}
        .tarjeta{background:var(--surface);border:1px solid var(--rule);border-radius:12px;padding:1.1rem;margin:1rem 0}
        .aviso{border-radius:10px;padding:.8rem 1rem;margin:1rem 0;font-weight:600}
        .aviso.bueno{background:color-mix(in srgb,var(--ok) 15%,transparent);border-left:4px solid var(--ok)}
        .aviso.malo{background:color-mix(in srgb,var(--danger) 13%,transparent);border-left:4px solid var(--danger)}
        .boton{display:block;width:100%;font:inherit;font-size:1.05rem;font-weight:700;padding:.9rem;border:0;border-radius:10px;
               background:var(--accent);color:var(--surface);cursor:pointer;text-align:center;text-decoration:none}
        input[type=text],select{width:100%;font:inherit;font-size:1.05rem;padding:.75rem;border-radius:10px;
               border:1px solid var(--rule);background:var(--ground);color:var(--ink)}
        label{display:block;font-size:.85rem;color:var(--ink-soft);margin:.6rem 0 .3rem}
        .muted{color:var(--muted);font-size:.9rem}
        .etapas{display:flex;gap:.35rem;margin:.6rem 0}
        .etapas i{flex:1;height:.45rem;border-radius:99px;background:var(--rule)}
        .etapas i.hecha{background:var(--accent)}
        .etapas i.actual{background:color-mix(in srgb,var(--accent) 45%,var(--rule))}
        .secuencia{display:grid;grid-template-columns:repeat(4,1fr);gap:.6rem;margin:1rem 0}
        .secuencia div{aspect-ratio:1;border-radius:14px;display:flex;align-items:center;justify-content:center;
               font-size:1.6rem;font-weight:800;color:#fff;text-shadow:0 1px 2px rgb(0 0 0/.35)}
        .secuencia small{display:block;font-size:.7rem;font-weight:600}
        .error{color:var(--danger);font-size:.9rem;margin-top:.4rem}
    </style>
</head>
<body>
    <main class="@yield('ancho')">
        <div class="marca">{{ config('fabos.lab.name') }} · Recorrido</div>
        @yield('content')
    </main>
</body>
</html>
