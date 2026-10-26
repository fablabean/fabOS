@extends('layouts.juego')

@section('title', 'Inscribirse · ' . $partida->nombre)

@section('content')
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

            <label for="integrantes">Integrantes, uno por línea</label>
            <textarea id="integrantes" name="integrantes" rows="4" maxlength="1200"
                      style="width:100%;font:inherit;font-size:1.05rem;padding:.75rem;border-radius:10px;border:1px solid var(--rule);background:var(--ground);color:var(--ink)">{{ old('integrantes') }}</textarea>
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
