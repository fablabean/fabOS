<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('fabos.lab.name') . ' · fabOS')</title>
    <meta name="description" content="@yield('description', config('fabos.lab.tagline') . ' de ' . config('fabos.lab.institution') . '.')">
    <style>
        :root{
            --ground:#E8E8E2; --surface:#F6F6F2; --ink:#191A16; --ink-soft:#3D4038;
            --muted:#6E7066; --rule:#C7C7BD; --accent:#0D6E63;
            --banner:#171A15; --banner-ink:#F3F4EC; --banner-muted:#A0A697; --banner-accent:#5CC9B8;
        }
        @media (prefers-color-scheme:dark){
            :root{
                --ground:#131511; --surface:#1B1E19; --ink:#E9EAE2; --ink-soft:#C6C8BC;
                --muted:#93968A; --rule:#2F342B; --accent:#5CC9B8;
            }
        }
        *{box-sizing:border-box}
        body{margin:0;background:var(--ground);color:var(--ink);line-height:1.65;
             font-family:system-ui,"Segoe UI","Helvetica Neue",Arial,sans-serif}
        a{color:var(--accent)}

        /* ---------- barra ---------- */
        .nav{
            position:sticky;top:0;z-index:10;background:color-mix(in srgb,var(--ground) 92%,transparent);
            backdrop-filter:blur(8px);border-bottom:1px solid var(--rule);
            padding:.75rem 1.4rem;display:flex;align-items:center;gap:1.4rem;flex-wrap:wrap;
        }
        /* «marca-sitio» y no «marca»: la portada usa .marca para las etiquetas
           del roadmap, y dos componentes con el mismo nombre acaban pisándose. */
        .marca-sitio{
            display:flex;align-items:center;gap:.55rem;font-weight:800;letter-spacing:-.03em;
            font-size:1.2rem;text-decoration:none;color:var(--ink);
        }
        /* La palabra va envuelta: con `display:flex` y `gap`, «fab» y
           <em>OS</em> son DOS elementos y el hueco se metia entre ellos,
           partiendo la marca en «fab OS». */
        .marca-sitio .palabra{display:inline}
        .marca-sitio em{font-style:normal;color:var(--accent)}
        /* Sirve igual para el SVG en línea que para un logo propio en PNG. */
        .marca-sitio svg,.marca-sitio img{
            width:1.9rem;height:1.9rem;display:block;color:var(--accent);flex:none;
        }
        .nav nav{margin-left:auto;display:flex;gap:1.2rem;align-items:center;font-size:.92rem}
        .nav nav a{color:var(--ink-soft);text-decoration:none}
        .nav nav a:hover{color:var(--accent)}
        .btn{
            display:inline-block;background:var(--accent);color:var(--surface);text-decoration:none;
            padding:.55rem 1.1rem;border-radius:4px;font-weight:600;font-size:.92rem;border:0;cursor:pointer;
        }
        .btn:hover{filter:brightness(1.08)}
        /* El selector de la barra (.nav nav a) pesa más que .btn y le ganaba el
           color: por eso el botón salía con el texto del menú en vez del suyo.
           Se fija aquí, con la misma especificidad, para los dos temas. */
        .nav nav a.btn{color:var(--surface)}
        .nav nav a.btn:hover{color:var(--surface);filter:brightness(1.08)}
        .btn.claro{background:var(--banner-accent);color:var(--banner)}
        .btn.borde{background:transparent;color:var(--banner-ink);border:1px solid rgba(243,244,236,.35)}

        main{max-width:70rem;margin:0 auto;padding:0 1.4rem}
        section{padding:3.2rem 0}
        h1{font-size:clamp(1.8rem,4vw,2.6rem);letter-spacing:-.03em;line-height:1.1;margin:0 0 .6rem}
        h2{font-size:1.35rem;letter-spacing:-.02em;margin:0 0 .3rem}
        .rotulo{
            font-family:ui-monospace,Consolas,monospace;font-size:.68rem;letter-spacing:.18em;
            text-transform:uppercase;color:var(--muted);margin:0 0 .7rem;
        }
        p.lead{font-size:1.08rem;color:var(--ink-soft);max-width:44ch}

        footer{border-top:1px solid var(--rule);margin-top:3rem;padding:2rem 1.4rem;
               color:var(--muted);font-size:.88rem}
        footer .in{max-width:70rem;margin:0 auto;display:flex;gap:1.4rem;flex-wrap:wrap;align-items:center}
        @yield('styles')
    </style>
</head>
<body>

<div class="nav">
    <a class="marca-sitio" href="{{ route('publico.home') }}">
        <x-logo/>
        <span class="palabra">fab<em>OS</em></span>
    </a>
    <nav>
        {{-- «Reservas» y no «Equipos»: quien entra de fuera no viene a mirar
             un inventario, viene a usar el laboratorio. --}}
        <a href="{{ route('publico.equipos') }}">Reservas</a>
            <a href="{{ route('preguntas.index') }}">Preguntas</a>
        <a href="{{ route('formacion') }}">Formación</a>
        @auth
            <a href="{{ route('reservas.index') }}">Reservar</a>
            <a class="btn" href="{{ route('home') }}">Mi cuenta</a>
        @else
            <a class="btn" href="{{ route('login') }}">Ingresar</a>
        @endauth
    </nav>
</div>

@yield('content')

<footer>
    <div class="in">
        <strong style="color:var(--ink)">{{ config('fabos.lab.name') }}</strong>
        <span>{{ config('fabos.lab.institution') }} · {{ config('fabos.lab.city') }}</span>
        @if (config('fabos.lab.network'))
            <span style="margin-left:auto">Parte de la red {{ config('fabos.lab.network') }}</span>
        @endif
    </div>
</footer>

</body>
</html>
