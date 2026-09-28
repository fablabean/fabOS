@extends('layouts.app')
@section('title', 'Solicitud recibida · ' . config('fabos.lab.name'))

{{--
    «Quedó anotado», en su propia página (§11).

    Antes salía arriba del formulario, y en el teléfono quedaba fuera de la
    vista: parecía que no había pasado nada, y la gente volvía a enviar el
    mismo proyecto dos y tres veces. Aquí no hay formulario que reenviar, y
    lo primero que se ve es que llegó.
--}}
@section('content')
    <div class="panel recibida">
        <div class="check" aria-hidden="true">✓</div>

        <h1>
            @if ($recibida['repetido'])
                Esta solicitud ya nos había llegado
            @else
                ¡Listo! Tu solicitud llegó
            @endif
        </h1>

        <p class="codigo">
            <span>{{ $recibida['nombre'] }}</span>
            <strong>{{ $recibida['codigo'] }}</strong>
        </p>

        @if ($recibida['repetido'])
            <p>No la volvimos a anotar: con una basta. Si quieres agregar algo, hazlo desde tu cuenta.</p>
        @elseif ($recibida['correo'])
            <p>Te mandamos un correo a <strong>{{ $recibida['correo'] }}</strong> con ese código.</p>
        @endif

        @if ($recibida['aviso'])
            <p class="ojo"><strong>Ojo con la fecha.</strong> {{ $recibida['aviso'] }}</p>
        @endif

        <h2>Qué sigue</h2>
        <ol>
            <li>Alguien del laboratorio la revisa: si cabe, con qué máquinas y cuánto tomaría.</li>
            <li>Cuando tengamos una propuesta te llega por correo, con un enlace donde la ves completa.</li>
            <li>Mientras tanto, la sigues en <strong>Mi cuenta → Mis proyectos</strong>.</li>
        </ol>

        <div class="botones">
            @auth
                <a class="boton" href="{{ route('home') }}">Ir a Mi cuenta</a>
                <p class="foot" id="aviso-redireccion">Te llevamos a Mi cuenta en <span id="segundos">8</span> segundos.</p>
            @else
                <a class="boton" href="{{ route('login') }}">Entrar a Mi cuenta</a>
                <p class="foot">Tu cuenta ya existe, con el correo que escribiste: entras con un código que te llega a ese correo, sin contraseña.</p>
            @endauth
            <a class="secundario" href="{{ route('publico.home') }}">Volver al inicio</a>
        </div>
    </div>

    <style>
        .recibida { max-width:38rem; margin:1.5rem auto; text-align:left; }
        .recibida .check { width:3.2rem; height:3.2rem; border-radius:999px; background:var(--ok); color:var(--surface);
                           display:flex; align-items:center; justify-content:center; font-size:1.7rem; font-weight:700; }
        .recibida h1 { margin:.8rem 0 .6rem; }
        .recibida .codigo { display:flex; flex-direction:column; gap:.1rem; margin:0 0 1rem; padding:.8rem 1rem;
                            border-left:4px solid var(--ok); background:color-mix(in srgb, var(--ok) 8%, transparent); }
        .recibida .codigo strong { font-family:ui-monospace,Consolas,monospace; font-size:1.3rem; letter-spacing:.04em; }
        .recibida .ojo { border-left:3px solid var(--warn); padding-left:.7rem; }
        .recibida h2 { font-size:1rem; margin:1.4rem 0 .4rem; }
        .recibida ol { margin:0; padding-left:1.2rem; line-height:1.6; }
        .recibida .botones { display:flex; flex-direction:column; align-items:flex-start; gap:.6rem; margin-top:1.6rem; }
        .recibida .boton { display:inline-block; background:var(--accent); color:var(--surface); text-decoration:none;
                           padding:.7rem 1.2rem; border-radius:4px; font-weight:600; }
        .recibida .secundario { color:var(--accent); }
        .recibida .foot { margin:0; }
    </style>

    @auth
        <script>
            // A Mi cuenta, sola, tras unos segundos: ahí está la solicitud con
            // todo lo demás. Se ve la cuenta atrás, y el botón lleva ya.
            (function () {
                let quedan = 8;
                const cifra = document.getElementById('segundos');
                const reloj = setInterval(function () {
                    quedan -= 1;
                    if (cifra) cifra.textContent = quedan;
                    if (quedan <= 0) {
                        clearInterval(reloj);
                        window.location.href = @js(route('home'));
                    }
                }, 1000);
            })();
        </script>
    @endauth
@endsection
