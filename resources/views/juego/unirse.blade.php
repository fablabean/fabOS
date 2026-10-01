@extends('layouts.juego')

@section('title', 'Unirse a un equipo')

@section('content')
    <h1>¿De qué equipo son?</h1>

    @if ($aviso)
        <div class="aviso bueno">{{ $aviso }}</div>
    @endif

    <form method="POST" action="{{ route('juego.entrar') }}" class="tarjeta">
        @csrf
        <input type="hidden" name="volver" value="{{ $volverA }}">
        <label for="codigo">Código del equipo</label>
        <input id="codigo" name="codigo" type="text" required autocomplete="off" autocapitalize="characters"
               maxlength="12" value="{{ old('codigo') }}" placeholder="Ej. K7M2QX" style="text-transform:uppercase;letter-spacing:.15em">
        @error('codigo') <p class="error">{{ $message }}</p> @enderror
        <p class="muted">Se lo dio quien guía el recorrido al armar los equipos. Basta con un celular por equipo.</p>
        <button class="boton" type="submit">Entrar</button>
    </form>
@endsection
