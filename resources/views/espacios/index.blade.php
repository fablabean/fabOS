@extends('layouts.app')
@section('title', 'Reservar un espacio · fabOS')

@section('styles')
    .card.recorrido { border-left:3px solid var(--accent); }
@endsection

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
                @if ($e->esTodoElLaboratorio())
                    {{-- El recorrido: ocupa el laboratorio entero sin cerrarlo.
                         Caben treinta a la vez, en grupos de quince, y las
                         máquinas siguen trabajando. --}}
                    <a class="card recorrido" href="{{ route('espacios.show', $e) }}">
                        <span class="pill">Recorrido · hasta {{ $e->capacity ?: 30 }} personas</span>
                        <span class="n">Recorrido por {{ mb_strtolower($e->name) }}</span>
                        <span class="m">
                            En grupos de {{ \App\Services\Booking\EspacioBookingService::GRUPO_DE_RECORRIDO }},
                            dos a la vez. No interrumpe lo que esté en marcha.
                        </span>
                    </a>
                    @continue
                @endif

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
