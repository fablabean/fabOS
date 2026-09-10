@extends('layouts.app')
@section('title', 'Mi perfil · ' . config('fabos.lab.name'))

@php $tz = config('fabos.lab.timezone'); @endphp

@section('content')
    <p class="volver"><a href="{{ route('home') }}" class="volver">← Mi cuenta</a></p>

    {{-- La foto o las iniciales, y desde aqui mismo se cambia: es el
         circulo que sale en la barra de todo el sitio. --}}
    <div class="saludo">
        {{-- El círculo con la cámara encima: pulsarla elige la foto y la sube. --}}
        <form method="POST" action="{{ route('cuenta.foto') }}" enctype="multipart/form-data" class="foto-form">
            @csrf
            <label class="circulo-foto" title="{{ $usuario->photo_path ? 'Cambiar la foto' : 'Poner una foto' }}">
                <x-avatar :usuario="$usuario" tamano="4.6rem"/>
                <span class="camara" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/>
                    </svg>
                </span>
                <input type="file" name="foto" accept="image/*" onchange="this.form.submit()">
                <span class="sr-only">{{ $usuario->photo_path ? 'Cambiar la foto' : 'Poner una foto' }}</span>
            </label>
        </form>
        <div>
            <h1 style="margin:0">Hola, {{ $usuario->name }}</h1>
            <p class="help" style="margin:.2rem 0 0">
                <span class="who">{{ $usuario->email }}</span>
                · Categoría <strong>{{ $usuario->category?->name ?? 'sin asignar' }}</strong>
                @unless ($usuario->category_confirmed)
                    <span class="pill warn" style="margin-left:.4rem">pendiente de confirmar</span>
                @endunless
            </p>

            {{-- El nombre y la foto. --}}
            <details class="plegable perfil" open>
                <summary>Nombre</summary>
                <form method="POST" action="{{ route('cuenta.perfil.guardar') }}">
                    @csrf
                    <label for="perfil-nombre">Nombre</label>
                    <input id="perfil-nombre" name="name" type="text" value="{{ old('name', $usuario->name) }}" required maxlength="255">
                    @error('name') <p class="msg error" style="margin:.2rem 0 0">{{ $message }}</p> @enderror
                    <button type="submit">Guardar</button>
                </form>
                @if ($usuario->photo_path)
                    <form method="POST" action="{{ route('cuenta.foto.quitar') }}" style="margin-top:.4rem">
                        @csrf
                        <button type="submit" class="foto-quitar">Quitar la foto</button>
                    </form>
                @endif
            </details>
            @error('foto') <p class="msg error" style="margin:.4rem 0 0">{{ $message }}</p> @enderror
        </div>
    </div>
    <style>
        .saludo{display:flex;gap:1rem;align-items:flex-start;margin-bottom:1.4rem}
        .saludo .avatar{font-size:1.5rem}
        .foto-form{margin:0;flex:none}
        .circulo-foto{position:relative;display:inline-block;cursor:pointer;line-height:0}
        .circulo-foto input{display:none}
        .circulo-foto .camara{
            position:absolute;right:-.15rem;bottom:-.15rem;width:1.6rem;height:1.6rem;border-radius:50%;
            display:inline-flex;align-items:center;justify-content:center;background:var(--surface);
            color:var(--ink);border:1px solid var(--rule);box-shadow:0 1px 4px rgba(0,0,0,.15);
        }
        .circulo-foto:hover .camara{color:var(--accent);border-color:var(--accent)}
        .sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
        .perfil{margin-top:.5rem}
        .perfil > summary{font-size:.85rem;color:var(--link);cursor:pointer}
        .foto-quitar{background:none;border:0;padding:0;margin:0;font:inherit;font-size:.82rem;color:var(--muted);cursor:pointer;text-decoration:underline}
    </style>

    {{-- ------------------------------------------------ mi calendario --}}
    <h2>Mi calendario</h2>
    <div class="panel">
        <p class="help" style="margin-top:0">
            El enlace de cada reserva se descarga y se guarda: es una <strong>foto</strong>. Si
            luego cambia la hora, esa copia no se entera.
        </p>

        @if (auth()->user()->calendar_token)
            <p style="margin:.8rem 0 .3rem"><strong>Tu calendario, siempre al día</strong></p>
            <p class="help" style="margin-top:0">
                Pega esta dirección en Outlook —<em>Agregar calendario → Suscribirse desde
                web</em>— y tus reservas y asesorías aparecen solas y se actualizan.
            </p>
            <input type="text" readonly onclick="this.select()"
                   value="{{ route('calendario.suscripcion', auth()->user()->calendar_token) }}"
                   style="width:100%;font-family:ui-monospace,Consolas,monospace;font-size:.8rem">
            <p class="help">
                Es <strong>secreta</strong>: quien la tenga ve tu agenda del laboratorio. No la
                publiques.
            </p>
            <form method="POST" action="{{ route('calendario.suscribirme') }}">
                @csrf
                <button type="submit" class="secundario">Cambiar la dirección</button>
            </form>
            <p class="help">
                Cambiarla deja de servir la anterior, por si la compartiste sin querer.
            </p>
        @else
            <p class="help">
                También puedes suscribir tu Outlook y que aparezcan solas, sin descargar nada
                cada vez.
            </p>
            <form method="POST" action="{{ route('calendario.suscribirme') }}">
                @csrf
                <button type="submit">Crear mi dirección de calendario</button>
            </form>
        @endif
    </div>

    {{-- ------------------------------------ mi agenda de fuera --}}
    <div class="panel">
        <p style="margin:0 0 .3rem"><strong>Tu calendario de la Universidad</strong></p>
        <p class="help" style="margin-top:0">
            Si pegas aquí tu calendario publicado de Outlook, fabOS mira si ya tienes algo a
            esa hora y <strong>deja de ofrecer esa franja</strong>: ni asesorías, ni
            acompañamientos, ni traspasos de otra persona. Es de solo lectura: no escribe nada
            en tu calendario, ni podría.
        </p>

        @if (auth()->user()->external_calendar_url)
            {{-- Decir si funciona. Una dirección mal copiada y una buena se ven
                 igual hasta que alguien nota, semanas después, que sus horas
                 ocupadas se seguían ofreciendo. --}}
            @if ($agenda['ok'])
                <p class="msg" style="margin:.8rem 0">
                    <strong>Se está leyendo.</strong>
                    {{ $agenda['cuantos'] . ' ' . ($agenda['cuantos'] === 1 ? 'compromiso' : 'compromisos') }}
                    en las próximas semanas; esas horas no se ofrecen para asesorías.
                    @if ($agenda['leido'])
                        <span class="help">
                            Última lectura {{ $agenda['leido']->timezone($tz)->format('d/m H:i') }}.
                        </span>
                    @endif
                </p>
            @else
                <p class="msg error" style="margin:.8rem 0">
                    <strong>No se pudo leer.</strong> Comprueba que sea el enlace <em>ICS</em> de
                    un calendario <strong>publicado</strong> —no el de compartir con alguien— y
                    que siga publicado en Outlook.
                </p>
            @endif
        @endif

        <form method="POST" action="{{ route('calendario.agenda') }}">
            @csrf
            <label for="agenda">Dirección del calendario publicado</label>
            <input type="url" id="agenda" name="url" maxlength="2000"
                   value="{{ auth()->user()->external_calendar_url }}"
                   placeholder="https://outlook.office365.com/owa/calendar/…/reachcalendar.ics">
            <p class="help">
                En Outlook web: <em>Configuración → Calendario → Calendarios compartidos →
                Publicar calendario</em>. Copia el enlace <strong>ICS</strong>. Déjalo vacío
                para quitarlo.
            </p>
            <button type="submit">Guardar mi calendario</button>
        </form>

        @if (auth()->user()->external_calendar_url)
            {{-- Volver a leerlo ahora: se guarda media hora, y quien acaba de
                 arreglar algo en Outlook no quiere esperar media hora para
                 saber si ya sirve. --}}
            <form method="POST" action="{{ route('calendario.comprobar') }}" style="margin-top:.6rem">
                @csrf
                <button type="submit" class="secundario">Comprobar ahora</button>
            </form>
        @endif

        <p class="help">
            Dos cosas que conviene saber, y no son fallos: Outlook regenera ese enlace
            <strong>cada pocas horas</strong>, así que una reunión de esta mañana puede tardar
            en aparecer; y según cómo lo publiques, los eventos llegan sin título —da igual,
            aquí solo hace falta saber cuándo—.
        </p>
    </div>

    {{-- ------------------------------------------------ como entro --}}
    <h2>Cómo entro</h2>
    <div class="panel">
        @if (auth()->user()->tieneSegundoFactor())
            <p>
                Entras con el código de tu <strong>aplicación de autenticación</strong>. No
                dependes del correo: el código lo genera tu teléfono.
            </p>
        @else
            <p>
                Ahora entras con un código que te llega al correo. Puedes usar una
                <strong>aplicación de autenticación</strong> en su lugar: el código lo genera
                tu teléfono, así que funciona aunque el correo tarde o no llegue.
            </p>
        @endif
        <p><a href="{{ route('cuenta.app') }}">Configurar la aplicación de autenticación →</a></p>
    </div>

    {{-- ---------------------------------------------------- avisos --}}
    @if ($avisos->isNotEmpty())
        <h2>Qué avisos quiero recibir</h2>
        <div class="panel">
            <form method="POST" action="{{ route('cuenta.avisos') }}">
                @csrf
                @foreach ($avisos as $fila)
                    <label style="display:flex;gap:.6rem;align-items:flex-start;text-transform:none;
                                  letter-spacing:0;font-family:inherit;font-size:.92rem;
                                  color:var(--ink);margin-bottom:.7rem">
                        <input type="checkbox" style="width:auto;margin-top:.25rem"
                               name="avisos[{{ $fila['plantilla']->key }}]" value="1"
                               @checked($fila['recibe'])>
                        <span>
                            {{ $fila['plantilla']->name }}
                            @if ($fila['plantilla']->description)
                                <span class="quien" style="display:block">{{ $fila['plantilla']->description }}</span>
                            @endif
                        </span>
                    </label>
                @endforeach

                <button type="submit">Guardar</button>
            </form>

            <p class="foot" style="margin-top:.9rem">
                Hay avisos que no se pueden desactivar —que tu equipo entró a mantenimiento,
                que se liberó tu reserva—: enterarte tarde de eso te haría perder el viaje.
            </p>
        </div>
    @endif

    {{-- ---------------------------------------------------- carné --}}
    @if (\App\Support\Settings::carnetLoginEnabled())
        <h2>Carné digital</h2>
        <div class="panel">
            @if ($usuario->carnet_subject)
                <p style="margin:0">
                    Vinculado desde
                    {{ $usuario->carnet_linked_at?->timezone($tz)->format('d/m/Y') }}.
                    Ya puedes entrar escaneándolo.
                </p>
            @else
                <p class="help" style="margin:0 0 .4rem">
                    Vincula tu carné para entrar escaneándolo, sin esperar el código del correo.
                </p>
                <form method="POST" action="{{ route('carnet.link') }}">
                    @csrf
                    <label for="carnet">Enlace de tu carné</label>
                    <input id="carnet" name="carnet" type="text" required
                           placeholder="Pega aquí el enlace del carné digital">
                    <button type="submit">Vincular</button>
                </form>
            @endif
        </div>
    @endif
@endsection
