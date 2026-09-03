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
                        @foreach ([60, 90, 120, 180, 240, 360, 480] as $min)
                            @php($h = intdiv($min, 60))
                            @php($m = $min % 60)
                            <option value="{{ $min }}" @selected(old('duracion', $duracion) == $min)>
                                {{ $h }} hora{{ $h > 1 ? 's' : '' }}{{ $m ? ' ' . $m . ' min' : '' }}
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

        <button type="submit">
            {{ $espacio->esTodoElLaboratorio() ? 'Reservar el recorrido' : 'Reservar el espacio' }}
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
    </style>
@endsection
