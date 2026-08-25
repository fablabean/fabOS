@extends('layouts.app')
@section('title', 'Reservar un espacio · fabOS')

@section('content')
    <h1>Reservar un espacio</h1>

    @if ($franjaHoy)
        <p class="help">
            Hoy el laboratorio atiende de <strong>{{ substr($franjaHoy[0], 0, 5) }}</strong>
            a <strong>{{ substr($franjaHoy[1], 0, 5) }}</strong>.
        </p>
    @else
        <p class="help">
            Hoy no hay nadie en jornada presencial, así que el laboratorio no atiende.
        </p>
    @endif

    <p class="help">
        Una sala se reserva para trabajar en grupo, dar una clase o montar algo grande.
        Dentro puedes tomar las herramientas que necesites.
    </p>

    @if ($espacios->isEmpty())
        <div class="panel">
            <p style="margin:0">Todavía no hay espacios que se puedan reservar.</p>
        </div>
    @else
        <div class="cards">
            @foreach ($espacios as $e)
                <a class="card" href="{{ route('espacios.show', $e) }}">
                    <span class="pill">
                        {{ $e->capacity ? 'Hasta ' . $e->capacity . ' personas' : 'Sin aforo fijado' }}
                    </span>
                    <span class="n">{{ $e->name }}</span>
                    <span class="m">
                        {{ $e->areas->pluck('name')->implode(' · ') ?: 'Sin área asignada' }}
                        @if ($e->assets_count)
                            — {{ $e->assets_count }} equipos dentro
                        @endif
                    </span>
                </a>
            @endforeach
        </div>
    @endif
@endsection
