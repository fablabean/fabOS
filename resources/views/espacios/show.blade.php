@extends('layouts.app')
@section('title', 'Reservar ' . $espacio->name . ' · fabOS')

@section('content')
    <p class="rotulo"><a href="{{ route('espacios.index') }}">← Volver a espacios</a></p>
    @if ($espacio->esTodoElLaboratorio())
        {{-- El recorrido: ocupa el laboratorio entero sin cerrarlo. Cerrarlo
             de verdad -una operación- se programa desde el panel, no desde
             aquí. --}}
        <h1>Recorrido por {{ mb_strtolower($espacio->name) }}</h1>

        <p class="help">
            Hasta {{ $espacio->capacity ?: 30 }} personas a la vez, en grupos de
            {{ \App\Services\Booking\EspacioBookingService::GRUPO_DE_RECORRIDO }}: dos recorridos
            pueden ir en paralelo. No interrumpe lo que esté en marcha —las máquinas siguen
            trabajando— y alguien del equipo acompaña.
        </p>
    @else
        <h1>{{ $espacio->name }}</h1>

        <p class="help">
            {{ $espacio->areas->pluck('name')->implode(' · ') ?: 'Sin área asignada' }}
            @if ($espacio->capacity) · caben {{ $espacio->capacity }} personas @endif
            @if ($espacio->seComparte())
                · se comparte por puestos: varias reservas caben a la vez, cada una toma los que pide
            @endif
        </p>
    @endif

    @error('fecha') <p class="msg error">{{ $message }}</p> @enderror

    <form method="POST" action="{{ route('espacios.store', $espacio) }}">
        @csrf

        <div class="panel">
            <h2 style="margin-top:0">Cuándo y cuántos</h2>

            <div class="agenda">
                <div class="agenda-campo">
                    <label for="fecha">Fecha</label>
                    <input id="fecha" name="fecha" type="date" required
                           min="{{ now(config('fabos.lab.timezone'))->format('Y-m-d') }}"
                           value="{{ old('fecha', $desde->format('Y-m-d')) }}">
                </div>

                <div class="agenda-campo">
                    <label for="inicio">Hora de inicio</label>
                    <input id="inicio" name="inicio" type="time" required step="900"
                           value="{{ old('inicio', $desde->format('H:i')) }}">
                </div>

                <div class="agenda-campo">
                    <label for="duracion">Duración</label>
                    <select id="duracion" name="duracion" required>
                        @foreach (\App\Services\Booking\EspacioBookingService::DURACIONES as $min)
                            <option value="{{ $min }}" @selected(old('duracion', $duracion) == $min)>
                                {{ \App\Services\Booking\EspacioBookingService::enHoras($min) }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="agenda-campo">
                    <label for="participantes">Cuántas personas</label>
                    <input id="participantes" name="participantes" type="number" required
                           min="1" max="500"
                           value="{{ old('participantes', 1) }}">
                </div>

                <div class="agenda-campo agenda-ancho">
                    <label for="proposito">
                        ¿Para qué? <span style="text-transform:none;letter-spacing:0">(opcional)</span>
                    </label>
                    <input id="proposito" name="proposito" type="text" maxlength="500"
                           placeholder="Taller de introducción a la impresión 3D"
                           value="{{ old('proposito') }}">
                </div>
            </div>

            @error('participantes') <p class="msg error">{{ $message }}</p> @enderror

            {{-- Lo que le espera a esta reserva, dicho ANTES de enviarla.

                 Alguien pedía la sala a las cuatro por ocho horas, la reserva
                 se iba a la bandeja por caer fuera de la jornada y quien la
                 pedía no se enteraba: creía tener la sala. Ahora se dice aquí
                 mismo, con alternativas que sí se confirman solas. Pedirlo
                 fuera se puede igual: se advierte, no se prohíbe.

                 Viene resuelto del servidor —se lee sin JavaScript, y las
                 alternativas son enlaces que recargan con esa hora puesta— y
                 el script de abajo lo refresca sin recargar cuando lo hay. --}}
            <div id="jornada" class="jornada{{ $jornada['cubierta'] ? '' : ' aviso' }}"
                 data-url="{{ route('espacios.jornada', $espacio) }}" aria-live="polite">
                <p><strong>{{ $jornada['titulo'] }}</strong></p>
                <p>{{ $jornada['mensaje'] }}</p>
                @if ($jornada['opciones'])
                    <p class="j-alt">Sin visto bueno, lo más parecido:</p>
                    <div class="j-opciones">
                        @foreach ($jornada['opciones'] as $o)
                            <a href="{{ route('espacios.show', ['space' => $espacio, 'fecha' => $o['fecha'], 'inicio' => $o['inicio'], 'duracion' => $o['duracion']]) }}"
                               data-fecha="{{ $o['fecha'] }}" data-inicio="{{ $o['inicio'] }}"
                               data-duracion="{{ $o['duracion'] }}">{{ $o['etiqueta'] }}</a>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Para qué se toma. En recorrido se pasa por ahí: no bloquea la
                 sala y el aforo es guía. En operación se usa en exclusiva y el
                 aforo manda. El laboratorio entero solo se recorre desde aquí. --}}
            @unless ($espacio->esTodoElLaboratorio())
                <p style="margin:1rem 0 .4rem;font-weight:600">¿Para qué?</p>
                <div class="herramientas">
                    <label class="herramienta">
                        <input type="radio" name="modalidad" value="operacion" @checked(old('modalidad', 'operacion') === 'operacion')>
                        <span><strong>Usar el espacio</strong> <small style="opacity:.6">· en exclusiva; el aforo manda</small></span>
                    </label>
                    <label class="herramienta">
                        <input type="radio" name="modalidad" value="recorrido" @checked(old('modalidad') === 'recorrido')>
                        <span><strong>Recorrido</strong> <small style="opacity:.6">· se pasa por ahí; no bloquea nada</small></span>
                    </label>
                </div>
            @endunless

            {{-- Varios espacios en una sola reserva: quien monta una feria toma
                 el taller y la sala de al lado, y pedirlas de a una es dos
                 formularios por lo mismo. Se cancelan juntas. --}}
            @if (isset($otros) && $otros->isNotEmpty())
                <p style="margin:1rem 0 .4rem;font-weight:600">¿También otro espacio, a la misma hora?</p>
                <div class="herramientas">
                    @foreach ($otros as $o)
                        <label class="herramienta">
                            <input type="checkbox" name="espacios[]" value="{{ $o->id }}"
                                   @checked(in_array($o->id, old('espacios', [])))>
                            <span>
                                <strong>{{ $o->name }}</strong>
                                @if ($o->capacity) <small style="opacity:.6">· hasta {{ $o->capacity }}</small> @endif
                            </span>
                        </label>
                    @endforeach
                </div>
                <p class="foot" style="margin-top:.5rem">
                    Va todo en una sola reserva. Si alguno cae fuera de la jornada del equipo, la reserva
                    entera queda pendiente del visto bueno.
                </p>
            @endif
        </div>

        {{-- Las herramientas se toman DENTRO del espacio: es el uso normal del
             laboratorio, y por eso no se piden sueltas desde el catálogo. En un
             recorrido no se toma nada: se mira. --}}
        @unless ($espacio->esTodoElLaboratorio())
        <div class="panel">
            <h2 style="margin-top:0">¿Qué vas a necesitar?</h2>

            @if ($herramientas->isEmpty())
                <p class="help" style="margin:0">
                    No hay herramientas disponibles en este espacio para esa hora. Puedes reservar
                    el espacio igual.
                </p>
            @else
                <p class="help" style="margin-top:0">
                    Lo que marques queda reservado contigo. Si no lo vas a usar, déjalo libre para
                    quien lo necesite.
                </p>

                <div class="herramientas">
                    @foreach ($herramientas as $h)
                        <label class="herramienta">
                            <input type="checkbox" name="herramientas[]" value="{{ $h->id }}"
                                   @checked(in_array($h->id, old('herramientas', [])))>
                            <span>
                                <strong>{{ $h->name }}</strong>
                                @if ($h->puede_salir)
                                    <small style="opacity:.6">· portátil</small>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>

                <p class="foot" style="margin-top:.9rem">
                    Se listan las de este espacio y las portátiles de cualquier otro. Las demás no
                    salen de su sitio.
                </p>
            @endif
        </div>
        @endunless

        {{-- El botón dice lo que de verdad va a pasar al pulsarlo: reservar no
             es lo mismo que pedir, y confundirlos es lo que hacía que alguien
             se presentara a una puerta cerrada. --}}
        @php($etiquetaDentro = $espacio->esTodoElLaboratorio() ? 'Reservar el recorrido' : 'Reservar el espacio')
        @php($etiquetaFuera = 'Pedirlo igual: queda pendiente del visto bueno')
        <button type="submit" id="enviar"
                data-dentro="{{ $etiquetaDentro }}" data-fuera="{{ $etiquetaFuera }}">
            {{ $jornada['cubierta'] ? $etiquetaDentro : $etiquetaFuera }}
        </button>
    </form>

    <style>
        .agenda { display: grid; gap: .75rem 1rem; align-items: end; }
        .agenda-campo { display: flex; flex-direction: column; gap: .3rem; margin: 0; }
        .agenda-campo > label { margin: 0; }
        .agenda-campo > input, .agenda-campo > select { margin: 0; width: 100%; }

        @media (min-width: 720px) {
            .agenda { grid-template-columns: repeat(4, 1fr); }
            .agenda-ancho { grid-column: span 4; }
        }

        .herramientas {
            display: grid; gap: .5rem;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        }
        .herramienta {
            display: flex; gap: .55rem; align-items: flex-start; cursor: pointer;
            padding: .55rem .7rem; border: 1px solid rgba(128,128,128,.3); border-radius: .5rem;
        }
        .herramienta:has(input:checked) {
            border-color: #0f766e; background: rgba(15,118,110,.08);
        }

        .jornada {
            font-size: .9rem; margin: 1rem 0 0; padding: .7rem .9rem; border-radius: 4px;
            border-left: 3px solid var(--ok); background: color-mix(in srgb, var(--ok) 9%, transparent);
        }
        .jornada.aviso {
            border-left-color: var(--warn); background: color-mix(in srgb, var(--warn) 11%, transparent);
        }
        .jornada p { margin: 0 }
        .jornada p + p { margin-top: .35rem }
        .jornada .j-alt { margin-top: .6rem; color: var(--muted); font-size: .84rem }
        .j-opciones { display: flex; flex-wrap: wrap; gap: .4rem; margin-top: .4rem }
        .j-opciones a {
            display: inline-block; padding: .4rem .7rem; font-size: .82rem; font-weight: 600;
            border: 1px solid var(--rule); border-radius: 4px; background: var(--surface);
            color: var(--ink); text-decoration: none;
        }
        .j-opciones a:hover { border-color: var(--accent) }
    </style>

    {{-- Refresca el aviso de arriba mientras se elige la hora. Sin esto la
         página sigue sirviendo: el aviso ya viene resuelto del servidor y las
         alternativas son enlaces de verdad. --}}
    <script>
        (() => {
            const caja = document.getElementById('jornada');
            const form = caja?.closest('form');

            if (! caja || ! form) {
                return;
            }

            const enviar = document.getElementById('enviar');
            const campo = (id) => form.querySelector('#' + id);
            let espera, aborta;

            const pintar = (j) => {
                caja.className = 'jornada' + (j.cubierta ? '' : ' aviso');

                const alternativas = (j.opciones || []).map((o) => {
                    const a = document.createElement('a');
                    a.href = caja.dataset.url.replace('/jornada', '')
                        + '?fecha=' + encodeURIComponent(o.fecha)
                        + '&inicio=' + encodeURIComponent(o.inicio)
                        + '&duracion=' + encodeURIComponent(o.duracion);
                    a.dataset.fecha = o.fecha;
                    a.dataset.inicio = o.inicio;
                    a.dataset.duracion = o.duracion;
                    a.textContent = o.etiqueta;

                    return a;
                });

                caja.replaceChildren();
                caja.insertAdjacentHTML('beforeend', '<p><strong></strong></p><p></p>');
                caja.querySelector('strong').textContent = j.titulo;
                caja.querySelectorAll('p')[1].textContent = j.mensaje;

                if (alternativas.length) {
                    const rotulo = document.createElement('p');
                    rotulo.className = 'j-alt';
                    rotulo.textContent = 'Sin visto bueno, lo más parecido:';

                    const fila = document.createElement('div');
                    fila.className = 'j-opciones';
                    alternativas.forEach((a) => fila.append(a));

                    caja.append(rotulo, fila);
                }

                if (enviar) {
                    enviar.textContent = j.cubierta ? enviar.dataset.dentro : enviar.dataset.fuera;
                }
            };

            const consultar = () => {
                const datos = new URLSearchParams();
                datos.set('fecha', campo('fecha').value);
                datos.set('inicio', campo('inicio').value);
                datos.set('duracion', campo('duracion').value);
                form.querySelectorAll('input[name="espacios[]"]:checked')
                    .forEach((c) => datos.append('espacios[]', c.value));

                // A medio teclear una hora no hay nada que preguntar.
                if (! datos.get('fecha') || ! datos.get('inicio')) {
                    return;
                }

                aborta?.abort();
                aborta = new AbortController();

                fetch(caja.dataset.url + '?' + datos.toString(), {
                    headers: { Accept: 'application/json' },
                    signal: aborta.signal,
                })
                    .then((r) => (r.ok ? r.json() : null))
                    .then((j) => j && pintar(j))
                    // Sin red se queda lo último que se dijo, que es mejor que
                    // un hueco: el servidor lo vuelve a comprobar al enviar.
                    .catch(() => {});
            };

            const pedir = () => {
                clearTimeout(espera);
                espera = setTimeout(consultar, 300);
            };

            form.addEventListener('change', (e) => {
                if (e.target.matches('#fecha, #inicio, #duracion, input[name="espacios[]"]')) {
                    pedir();
                }
            });
            form.addEventListener('input', (e) => {
                if (e.target.matches('#fecha, #inicio')) {
                    pedir();
                }
            });

            // Tomar una alternativa mueve los campos, sin recargar ni perder lo
            // que ya se hubiera escrito abajo.
            caja.addEventListener('click', (e) => {
                const alternativa = e.target.closest('a[data-fecha]');

                if (! alternativa) {
                    return;
                }

                e.preventDefault();
                campo('fecha').value = alternativa.dataset.fecha;
                campo('inicio').value = alternativa.dataset.inicio;
                campo('duracion').value = alternativa.dataset.duracion;
                consultar();
            });
        })();
    </script>
@endsection
