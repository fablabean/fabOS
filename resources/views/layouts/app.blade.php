<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('fabos.lab.name'))</title>
    {{-- Estilos en línea a propósito: el arranque no depende de compilar assets. --}}
    <style>
        :root{
            --ground:#E8E8E2; --surface:#F6F6F2; --ink:#191A16; --ink-soft:#3D4038;
            --muted:#6E7066; --rule:#C7C7BD; --accent:#0D6E63; --link:#0B57D0;
            --ok:#0D6E63; --warn:#A45A17; --bad:#9B2C2C;
        }
        @media (prefers-color-scheme:dark){
            :root{
                --ground:#131511; --surface:#1B1E19; --ink:#E9EAE2; --ink-soft:#C6C8BC;
                --muted:#93968A; --rule:#2F342B; --accent:#5CC9B8; --link:#8AB4F8;
                --ok:#5CC9B8; --warn:#DFA163; --bad:#E08585;
            }
        }
        /* Los enlaces del texto: sin esto salian con el azul de serie del
           navegador, que sobre el fondo oscuro casi no se lee. */
        a{color:var(--link)}
        *{box-sizing:border-box}
        body{
            margin:0;background:var(--ground);color:var(--ink);line-height:1.6;
            font-family:system-ui,"Segoe UI","Helvetica Neue",Arial,sans-serif;
        }
        /* Posicion relativa: el menu plegado del telefono se ancla aqui. */
        header.top{position:relative;
            border-bottom:1px solid var(--rule);background:var(--surface);
            padding:.9rem 1.4rem;display:flex;align-items:center;gap:1.2rem;flex-wrap:wrap;
        }
        .brand{display:flex;align-items:center;gap:.5rem;font-weight:800;letter-spacing:-.03em;
               font-size:1.25rem;text-decoration:none;color:var(--ink)}
        /* La palabra va envuelta: con `display:flex` y `gap`, «fab» y
           <em>OS</em> son DOS elementos y el hueco se metia entre ellos,
           partiendo la marca en «fab OS». */
        .brand .palabra{display:inline}
        .brand em{font-style:normal;color:var(--accent)}
        .brand svg,.brand img{width:1.7rem;height:1.7rem;display:block;color:var(--accent);flex:none}
        header.top nav{display:flex;gap:1rem;margin-left:auto;align-items:center;font-size:.9rem}
        header.top a{color:var(--ink-soft);text-decoration:none}
        header.top a:hover{color:var(--accent)}
        /* «Mi cuenta» se ve igual dentro y fuera: el menu es el mismo, y una
           misma cosa que cambia de forma segun la pagina se lee como otra. */
        header.top a.btn{background:var(--accent);color:#fff;padding:.4rem .9rem;
                         border-radius:6px;font-weight:600}
        header.top a.btn:hover{color:#fff;filter:brightness(1.08)}
        .quien{font-family:ui-monospace,Consolas,monospace;font-size:.72rem;color:var(--muted)}
        main{max-width:62rem;margin:0 auto;padding:1.8rem 1.4rem 4rem}
        /* Una pagina pide todo el ancho definiendo la seccion «ancho» con el
           valor «completo». Ojo: escribir esa directiva aqui, aunque sea dentro
           de un comentario, la EJECUTA —Blade no sabe de comentarios CSS— y
           entonces se ensanchan todas las paginas. Por eso se describe en
           palabras y el ejemplo esta en cronograma.blade.php.

           62rem es la medida de una linea de texto comoda de leer, y por eso es
           el defecto; un cronograma no es texto, y estrujarlo en esa columna
           obliga a desplazarse en horizontal para ver el año. */
        main.completo{max-width:none}
        h1{font-size:1.5rem;letter-spacing:-.02em;margin:0 0 .3rem}
        h2{font-size:1rem;margin:2rem 0 .7rem;color:var(--muted);
           font-family:ui-monospace,Consolas,monospace;letter-spacing:.12em;text-transform:uppercase}
        p.help{color:var(--ink-soft);margin:0 0 1.4rem}
        .cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(15rem,1fr));gap:.7rem}
        .card{
            background:var(--surface);border:1px solid var(--rule);border-radius:6px;
            padding:.85rem .95rem;text-decoration:none;color:inherit;display:block;
        }
        .card:hover{border-color:var(--accent)}
        .card .n{font-weight:600;display:block;margin-bottom:.35rem}
        .card .m{font-size:.82rem;color:var(--muted);display:block}
        .pill{
            display:inline-block;font-size:.68rem;font-weight:700;letter-spacing:.06em;
            text-transform:uppercase;padding:.15rem .45rem;border-radius:3px;margin-bottom:.4rem;
        }
        .pill.ok{background:color-mix(in srgb,var(--ok) 16%,transparent);color:var(--ok)}
        .pill.warn{background:color-mix(in srgb,var(--warn) 16%,transparent);color:var(--warn)}
        .pill.bad{background:color-mix(in srgb,var(--bad) 14%,transparent);color:var(--bad)}
        .panel{background:var(--surface);border:1px solid var(--rule);border-radius:6px;padding:1.2rem;margin-bottom:1.2rem}
        label{display:block;font-family:ui-monospace,Consolas,monospace;font-size:.66rem;
              letter-spacing:.14em;text-transform:uppercase;color:var(--muted);margin:.9rem 0 .35rem}
        /* Los redondeles y las casillas quedan fuera: esta regla es para los
           campos donde se escribe. Aplicada a un radio le pone ancho completo y
           relleno, y el circulito acaba flotando en medio de una caja gris con
           el texto a saber donde. */
        input:not([type=radio]):not([type=checkbox]),select,textarea{
            width:100%;padding:.6rem .7rem;font-size:1rem;font-family:inherit;
            background:var(--ground);color:var(--ink);border:1px solid var(--rule);border-radius:4px;
        }

        input[type=radio],input[type=checkbox]{
            width:1.05rem;height:1.05rem;margin:0;accent-color:var(--accent);flex:none;
        }
        input:focus,select:focus,textarea:focus{outline:2px solid var(--accent);outline-offset:1px}
        button{
            margin-top:1.1rem;padding:.7rem 1.2rem;font-size:.95rem;font-weight:600;
            font-family:inherit;cursor:pointer;background:var(--accent);color:var(--surface);
            border:0;border-radius:4px;
        }
        button:hover{filter:brightness(1.08)}
        /* El boton de la accion menor: al lado del principal, sin competir con el. */
        button.secundario{background:transparent;color:var(--ink);border:1px solid var(--rule)}
        button.secundario:hover{border-color:var(--accent);filter:none}
        /* Un desplegable pequeno para las acciones que necesitan un dato mas:
           se abre en la misma fila y no se lleva a la persona a otra pantalla. */
        details.plegable{display:inline-block;text-align:left}
        details.plegable>summary{cursor:pointer;font-weight:600;color:var(--accent);list-style:none}
        details.plegable>summary::-webkit-details-marker{display:none}
        details.plegable[open]>summary{margin-bottom:.4rem}
        details.plegable form{display:flex;flex-direction:column;gap:.4rem;min-width:16rem;
            padding:.7rem;border:1px solid var(--rule);border-radius:4px;background:var(--ground)}
        details.plegable form label{margin:0}
        details.plegable form button{margin-top:.2rem;align-self:flex-start}
        .msg{font-size:.9rem;padding:.7rem .9rem;border-radius:4px;margin-bottom:1.2rem;
             border-left:3px solid var(--accent);background:color-mix(in srgb,var(--accent) 10%,transparent)}
        .msg.error{border-left-color:var(--bad);background:color-mix(in srgb,var(--bad) 10%,transparent)}
        ul.falta{margin:.5rem 0 0;padding-left:1.1rem;font-size:.9rem;color:var(--ink-soft)}
        table{width:100%;border-collapse:collapse;font-size:.9rem}
        th,td{text-align:left;padding:.5rem .6rem;border-bottom:1px solid var(--rule)}
        th{font-family:ui-monospace,Consolas,monospace;font-size:.66rem;letter-spacing:.1em;
           text-transform:uppercase;color:var(--muted)}
        .volver{font-size:.85rem;color:var(--muted);text-decoration:none}
        footer.pie-sitio{max-width:62rem;margin:0 auto;padding:1.4rem 1.4rem 2.2rem;border-top:1px solid var(--rule);
            display:flex;gap:1.2rem;flex-wrap:wrap;align-items:center;font-size:.85rem;color:var(--muted)}
        footer.pie-sitio strong{color:var(--ink)}
        footer.pie-sitio .powered{margin-left:auto}
        footer.pie-sitio em{font-style:normal;color:var(--accent);font-weight:700}

        /* En el teléfono las tablas se apilan: cada fila es una tarjeta y
           cada celda lleva encima el nombre de su columna (lo pone un script
           al final, leyendo la cabecera). Una tabla de cuatro columnas en
           360 px de ancho no cabe de ninguna otra manera sin desplazamiento
           lateral, y el desplazamiento lateral es donde se pierde el botón
           de la derecha, que es justo el que hay que pulsar. */
        @media (max-width:640px){
            main{padding:1.2rem .9rem 3rem}
            .panel{padding:.9rem}
            table:not(.fija) thead{display:none}
            table:not(.fija),table:not(.fija) tbody,table:not(.fija) tr,table:not(.fija) td{display:block;width:100%}
            table:not(.fija) tr{border:1px solid var(--rule);border-radius:6px;padding:.55rem .75rem;margin-bottom:.6rem;background:var(--ground)}
            table:not(.fija) td{border:0;padding:.25rem 0;text-align:left!important;white-space:normal!important;overflow-wrap:anywhere}
            table:not(.fija) td[data-label]::before{content:attr(data-label);display:block;font-family:ui-monospace,Consolas,monospace;
                font-size:.62rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:.05rem}
            table:not(.fija) td:empty{display:none}
            details.plegable form{min-width:0}
            img{max-width:100%;height:auto}
        }
    </style>
</head>
<body>
    <header class="top">
        <a class="brand" href="{{ route('home') }}"><x-logo/> <span class="palabra">{{ config('fabos.lab.name') }}</span></a>
        <nav>
            @include('partials.menu')
        </nav>
    </header>

    <main class="@yield('ancho')">
        @if (session('status'))
            <div class="msg">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="msg error">{{ $errors->first() }}</div>
        @endif

        @yield('content')
    </main>

    {{-- El sitio es del laboratorio; el sistema que lo mueve, fabOS. --}}
    <footer class="pie-sitio">
        <strong>{{ config('fabos.lab.name') }}</strong>
        <span>{{ config('fabos.lab.institution') }} · {{ config('fabos.lab.city') }}</span>
        <span class="powered">powered by fab<em>OS</em></span>
    </footer>

    {{-- El nombre de cada columna, puesto en cada celda para que en el
         teléfono, con la tabla apilada, se sepa qué es cada dato. --}}
    <script>
        document.querySelectorAll('table').forEach(function (tabla) {
            var titulos = Array.prototype.map.call(tabla.querySelectorAll('thead th'), function (th) { return th.textContent.trim(); });
            if (!titulos.length) return;
            tabla.querySelectorAll('tbody tr').forEach(function (fila) {
                Array.prototype.forEach.call(fila.children, function (celda, i) {
                    if (titulos[i]) celda.setAttribute('data-label', titulos[i]);
                });
            });
        });
    </script>
</body>
</html>
