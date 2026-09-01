{{--
    El menú, uno solo para las dos plantillas (§19).

    Había dos listas: la pública traía «Preguntas» y la de dentro «Proyectos»,
    «Tienda» y «Grabar». Cambiaban al navegar sin que nadie lo hubiera decidido
    —entrabas a una reserva y desaparecían secciones—, y eso hace dudar de si
    te falta un permiso o te equivocaste de sitio.

    Aquí está la lista una vez. Lo que depende de quién mira son unos pocos
    enlaces marcados, no el menú entero.

    En el teléfono se pliega tras un botón: ocho enlaces en una fila obligan al
    navegador a apretarlos hasta que no se pueden pulsar sin acertar, o a
    empujar el logo fuera de la pantalla.
--}}
<button type="button" class="menu-boton" aria-expanded="false" aria-controls="menu-enlaces">
    <span class="rayas" aria-hidden="true"></span>
    Menú
</button>

<div class="menu-enlaces" id="menu-enlaces">
    <a href="{{ route('publico.reservas') }}">Reservas</a>
    <a href="{{ route('formacion') }}">Formación</a>
    <a href="{{ route('proyectos.solicitar') }}">Proyectos</a>
    <a href="{{ route('tienda.publica') }}">Tienda</a>
    <a href="{{ route('preguntas.index') }}">Preguntas</a>

    @auth
        {{-- Grabar exige cuenta: el material queda atribuido a quien lo grabó. --}}
        <a href="{{ route('contenido.index') }}">Grabar</a>

        @if (auth()->user()->hasAnyRole(\App\Models\User::ROLES_BACKOFFICE))
            <a href="/admin">Backoffice</a>
        @endif

        <a class="btn" href="{{ route('home') }}">Mi cuenta</a>

        {{-- El correo y la salida, dentro del bloque plegable: si se quedaran
             fuera seguirían ocupando la barra del teléfono, que es justo lo
             que se estaba intentando despejar. --}}
        <span class="quien">{{ auth()->user()->email }}</span>
        <form method="POST" action="{{ route('logout') }}" class="salir">
            @csrf
            <button type="submit">Salir</button>
        </form>
    @else
        <a class="btn" href="{{ route('login') }}">Ingresar</a>
    @endauth
</div>

<style>
    .menu-enlaces{display:flex;gap:1rem;align-items:center}
    .menu-boton{display:none}
    .menu-enlaces .salir{display:inline;margin:0}
    .menu-enlaces .salir button{margin:0;padding:.3rem .7rem;font-size:.8rem}

    /* Bajo esta anchura no caben ocho enlaces en una fila: el navegador los
       aprieta hasta que no se pueden pulsar sin acertar. */
    @media (max-width:52rem){
        .menu-boton{
            display:inline-flex;align-items:center;gap:.5rem;margin:0 0 0 auto;
            padding:.45rem .8rem;font-size:.85rem;background:transparent;
            color:var(--ink-soft);border:1px solid var(--rule);border-radius:6px;
        }
        .menu-boton .rayas,
        .menu-boton .rayas::before,
        .menu-boton .rayas::after{
            display:block;width:1rem;height:2px;background:currentColor;content:"";
        }
        .menu-boton .rayas{position:relative}
        .menu-boton .rayas::before{position:absolute;top:-5px}
        .menu-boton .rayas::after{position:absolute;top:5px}

        /* Cerrado no está «escondido con CSS»: está fuera del orden de
           tabulación, para que no se navegue con el teclado a enlaces que no
           se ven. */
        .menu-enlaces[hidden]{display:none}
        .menu-enlaces{
            flex-direction:column;align-items:stretch;gap:0;
            position:absolute;left:0;right:0;top:100%;z-index:20;
            background:var(--surface);border-bottom:1px solid var(--rule);
            padding:.4rem 1.2rem 1rem;
        }
        .menu-enlaces > *{padding:.7rem 0;border-bottom:1px solid var(--rule)}
        .menu-enlaces > *:last-child{border-bottom:none}
        .menu-enlaces .btn{text-align:center;margin-top:.6rem;padding:.7rem}
        .menu-enlaces .quien{padding-top:1rem}
    }
</style>

<script>
    (function () {
        var boton = document.querySelector('.menu-boton');
        var enlaces = document.getElementById('menu-enlaces');

        if (!boton || !enlaces) return;

        // El estado inicial lo pone el javascript y no el HTML: si no cargara,
        // el menú se quedaría cerrado para siempre y sin forma de abrirlo.
        function estrecho() {
            return window.matchMedia('(max-width:52rem)').matches;
        }

        function pintar(abierto) {
            enlaces.hidden = estrecho() && !abierto;
            boton.setAttribute('aria-expanded', abierto ? 'true' : 'false');
        }

        pintar(false);

        boton.addEventListener('click', function () {
            pintar(boton.getAttribute('aria-expanded') !== 'true');
        });

        // Al girar el teléfono o ensanchar la ventana, el menú vuelve a caber:
        // dejarlo oculto ahí seria esconderlo sin botón que lo devuelva.
        window.addEventListener('resize', function () {
            pintar(!estrecho() ? true : boton.getAttribute('aria-expanded') === 'true');
        });
    })();
</script>
