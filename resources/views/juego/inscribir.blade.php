@extends('layouts.juego')

@section('title', 'Inscribirse · ' . $partida->nombre)

@section('content')
    <style>
        .persona{margin:.5rem 0;padding:.6rem 0;border-bottom:1px solid var(--rule)}
        input[type=email]{width:100%;font:inherit;font-size:1.05rem;padding:.75rem;border-radius:10px;
               border:1px solid var(--rule);background:var(--ground);color:var(--ink)}
        .casilla{display:flex;gap:.6rem;align-items:flex-start;font-size:.95rem;color:var(--ink);margin:.5rem 0}
        .casilla input{width:1.2rem;height:1.2rem;flex:none;margin-top:.15rem}
        .edad{display:flex;flex-wrap:wrap;gap:.3rem 1.2rem;align-items:center;margin:.5rem 0;font-size:.95rem}
        .edad .casilla{margin:0}
        .edad > span{flex-basis:100%}
        .leer{border-left:4px solid var(--accent);background:color-mix(in srgb,var(--accent) 10%,transparent);
              border-radius:8px;padding:.7rem .9rem;margin:.6rem 0 .3rem;font-size:1.05rem;line-height:1.45}
        .leer strong{display:block;font-size:.8rem;letter-spacing:.06em;text-transform:uppercase;color:var(--accent);margin-bottom:.3rem}

        /* Paso a paso, sin JavaScript. Primero el nombre; después, la edad;
           el correo solo si es mayor de edad; el aviso, cuando hay correo.
           Un navegador que no entienda :has() nunca muestra el correo: si
           algo falla, que falle sin guardar datos de nadie. */
        .persona .edad,.persona .adulto,.persona .es-menor,.persona .aviso-correo{display:none}
        .persona:has(.nombre:not(:placeholder-shown)) .edad{display:flex}
        .persona:has(.mayor:checked) .adulto{display:block}
        .persona:has(.menor:checked) .es-menor{display:block}
        .persona:has(.correo:not(:placeholder-shown)) .aviso-correo{display:block}
        .persona .error{display:block}
    </style>
    <h1>{{ $partida->nombre }}</h1>

    @if ($suyo)
        <div class="tarjeta">
            <h2>Este celular ya es de «{{ $suyo->nombre }}»</h2>
            <p class="muted">Basta con un celular por equipo.</p>
            <a class="boton" href="{{ $suyo->urlDelCelular() }}">Volver al juego</a>
        </div>

    @elseif ($partida->abiertaAhora())
        <form method="POST" action="{{ route('juego.inscribirse', $partida->codigo) }}" class="tarjeta">
            @csrf
            <h2>Inscriban a su equipo</h2>
            <label for="nombre">Nombre del equipo</label>
            <input id="nombre" name="nombre" type="text" required maxlength="80" autocomplete="off" value="{{ old('nombre') }}">
            @error('nombre') <p class="error">{{ $message }}</p> @enderror

            <h2 style="margin-top:1.2rem">Integrantes</h2>
            <p class="muted" style="margin-top:0">Escriban el nombre de cada uno. El correo es opcional y solo para mayores de edad.</p>
            {{-- Cuatro filas a la vista y el resto plegado. --}}
            @foreach (range(0, \App\Http\Controllers\JuegoController::INTEGRANTES - 1) as $n)
                @if ($n === 4)
                    <details @if (collect(old('integrantes', []))->slice(4)->pluck('nombre')->filter()->isNotEmpty()) open @endif>
                        <summary style="cursor:pointer;margin:.8rem 0;color:var(--accent);font-weight:600">Son más de cuatro</summary>
                @endif
                <div class="persona">
                    <input type="text" class="nombre" name="integrantes[{{ $n }}][nombre]" maxlength="80" autocomplete="off"
                           placeholder="Nombre {{ $n + 1 }}" aria-label="Nombre del integrante {{ $n + 1 }}" value="{{ old("integrantes.$n.nombre") }}">
                    @error("integrantes.$n.nombre") <p class="error">{{ $message }}</p> @enderror

                    {{-- La edad, antes que el correo: a un menor no se le puede escribir. --}}
                    <div class="edad" role="radiogroup" aria-label="¿Es mayor de edad?">
                        <span>¿Es mayor de edad?</span>
                        <label class="casilla"><input type="radio" class="mayor" name="integrantes[{{ $n }}][menor]" value="0" @checked(old("integrantes.$n.menor") === '0')> Sí</label>
                        <label class="casilla"><input type="radio" class="menor" name="integrantes[{{ $n }}][menor]" value="1" @checked(old("integrantes.$n.menor") === '1')> No, es menor</label>
                    </div>

                    <p class="es-menor muted">A los menores de edad no les pedimos correo.</p>

                    <div class="adulto">
                        <input type="email" class="correo" name="integrantes[{{ $n }}][correo]" maxlength="160" autocomplete="off" inputmode="email"
                               placeholder="Correo (opcional)" aria-label="Correo del integrante {{ $n + 1 }}" value="{{ old("integrantes.$n.correo") }}">
                        @error("integrantes.$n.correo") <p class="error">{{ $message }}</p> @enderror

                        {{-- Ley 1581 de 2012. Lo acepta cada persona, no quien llena por
                             todos: por eso es un texto para leerle, corto y en voz alta. --}}
                        <div class="aviso-correo">
                            <div class="leer">
                                <strong>Léanle esto a esta persona</strong>
                                «Vamos a guardar tu correo para escribirte sobre talleres, cursos y eventos de {{ config('fabos.lab.name') }}.
                                No lo compartimos con nadie y puedes pedir que lo borremos cuando quieras. ¿Estás de acuerdo?»
                            </div>
                            <label class="casilla">
                                <input type="checkbox" name="integrantes[{{ $n }}][autoriza]" value="1" @checked(old("integrantes.$n.autoriza"))>
                                <span><strong>Sí, aceptó.</strong> Si no acepta, borren el correo.</span>
                            </label>
                            @error("integrantes.$n.autoriza") <p class="error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>
                @if ($n === \App\Http\Controllers\JuegoController::INTEGRANTES - 1 && $n >= 4)
                    </details>
                @endif
            @endforeach

            <p class="muted">Inscríbanse desde un solo celular: con él juega todo el equipo. El reloj no arranca todavía.</p>
            <button class="boton" type="submit">Inscribirnos</button>
        </form>

    @else
        @php $proxima = $partida->estado === 'terminada' ? null : $partida->proximaJornada(); @endphp
        <div class="tarjeta">
            @if ($proxima)
                <h2>Todavía no abre</h2>
                <p>La próxima jornada empieza el {{ \App\Services\Recorrido\Juego::fecha($proxima['inicio']) }}.</p>
            @else
                <h2>Las inscripciones cerraron</h2>
                <p class="muted">Gracias por venir.</p>
            @endif
            <a href="{{ $partida->urlDelTablero() }}">Ver el tablero</a>
        </div>
    @endif
@endsection
