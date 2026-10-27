@extends('layouts.juego')

@section('title', 'Inscribirse · ' . $partida->nombre)

@section('content')
    <style>
        .persona{display:grid;grid-template-columns:1fr 1fr;gap:.4rem;margin:.4rem 0}
        .persona .error{grid-column:1 / -1;margin:0}
        @media (max-width:420px){ .persona{grid-template-columns:1fr;padding-bottom:.5rem;border-bottom:1px solid var(--rule)} }
        input[type=email]{width:100%;font:inherit;font-size:1.05rem;padding:.75rem;border-radius:10px;
               border:1px solid var(--rule);background:var(--ground);color:var(--ink)}
        .casilla{display:flex;gap:.6rem;align-items:flex-start;font-size:.9rem;margin:1rem 0 .4rem}
        .casilla input{width:1.2rem;height:1.2rem;flex:none;margin-top:.15rem}
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
            <p class="muted" style="margin-top:0">El correo es opcional; con él les contamos de lo que pasa en el laboratorio.</p>
            {{-- Sin JavaScript: cuatro filas a la vista y el resto plegado. --}}
            @foreach (range(0, \App\Http\Controllers\JuegoController::INTEGRANTES - 1) as $n)
                @if ($n === 4)
                    <details @if (collect(old('integrantes', []))->slice(4)->flatten()->filter()->isNotEmpty()) open @endif>
                        <summary style="cursor:pointer;margin:.8rem 0;color:var(--accent);font-weight:600">Son más de cuatro</summary>
                @endif
                <div class="persona">
                    <input type="text" name="integrantes[{{ $n }}][nombre]" maxlength="80" autocomplete="off"
                           placeholder="Nombre {{ $n + 1 }}" aria-label="Nombre del integrante {{ $n + 1 }}" value="{{ old("integrantes.$n.nombre") }}">
                    <input type="email" name="integrantes[{{ $n }}][correo]" maxlength="160" autocomplete="off" inputmode="email"
                           placeholder="Correo (opcional)" aria-label="Correo del integrante {{ $n + 1 }}" value="{{ old("integrantes.$n.correo") }}">
                    @error("integrantes.$n.nombre") <p class="error">{{ $message }}</p> @enderror
                    @error("integrantes.$n.correo") <p class="error">{{ $message }}</p> @enderror
                </div>
                @if ($n === \App\Http\Controllers\JuegoController::INTEGRANTES - 1 && $n >= 4)
                    </details>
                @endif
            @endforeach

            {{-- Ley 1581 de 2012, con el para qué escrito. Solo se exige si dejan correos. --}}
            <label class="casilla">
                <input type="checkbox" name="autoriza" value="1" {{ old('autoriza') ? 'checked' : '' }}>
                <span>
                    Los integrantes que dejamos correo autorizamos a {{ config('fabos.lab.name') }} a guardarlo y a
                    escribirnos sobre sus actividades. Podemos pedir que lo borren cuando queramos.
                </span>
            </label>
            @error('autoriza') <p class="error">{{ $message }}</p> @enderror

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
