@extends('layouts.app')
@section('title', 'Marcar llegada · Salón ' . $pantalla->salon . ' · fabOS')

@section('content')
    <p class="rotulo">Salón {{ $pantalla->salon }}</p>
    <h1>{{ $actividad->titulo }}</h1>
    <p class="help">{{ $actividad->horario() }}@if ($actividad->responsable) · Dirige: {{ $actividad->responsable }}@endif</p>

    <div class="panel">
        <h2 style="margin-top:0">Hola, {{ \Illuminate\Support\Str::before(auth()->user()->name, ' ') }}. ¿Cómo llegas?</h2>
        <form method="POST" action="{{ route('salon.llegada.store', $pantalla->codigo) }}" style="display:flex;gap:.6rem;flex-wrap:wrap">
            @csrf
            <button type="submit" name="rol" value="asiste">Vengo a la actividad</button>
            <button type="submit" name="rol" value="dirige" class="secundario">La dirijo yo</button>
        </form>
        <p class="foot"><a href="{{ route('salon.show', $pantalla->codigo) }}">← Volver</a></p>
    </div>
@endsection
