{{--
    El menú, uno solo para las dos plantillas (§19).

    Había dos listas: la pública traía «Preguntas» y la de dentro «Proyectos»,
    «Tienda» y «Grabar». Cambiaban al navegar sin que nadie lo hubiera decidido
    —entrabas a una reserva y desaparecían secciones—, y eso hace dudar de si
    te falta un permiso o te equivocaste de sitio.

    Aquí está la lista una vez. Lo que depende de quién mira son unos pocos
    enlaces marcados, no el menú entero.
--}}
<a href="{{ route('publico.equipos') }}">Reservas</a>
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
@else
    <a class="btn" href="{{ route('login') }}">Ingresar</a>
@endauth
