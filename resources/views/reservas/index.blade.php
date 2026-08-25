@extends('layouts.app')
@section('title', 'Reservar · fabOS')

@php
    /** El semáforo de §10 traducido a clases de la hoja de estilo. */
    $clase = fn (string $r) => match ($r) {
        \App\Services\Booking\Eligibility::AUTONOMO        => 'ok',
        \App\Services\Booking\Eligibility::CON_ACOMPANANTE => 'warn',
        default                                            => 'bad',
    };
    $etiqueta = fn (string $r) => match ($r) {
        \App\Services\Booking\Eligibility::AUTONOMO        => 'Puedes reservar',
        \App\Services\Booking\Eligibility::CON_ACOMPANANTE => 'Con acompañamiento',
        default                                            => 'Todavía no',
    };
@endphp

@section('content')
    <h1>Reservar equipo</h1>

    <p class="help">
        ¿Vas a trabajar en grupo o dar una clase?
        <a href="{{ route('espacios.index') }}">Reserva un espacio</a> y toma dentro las
        herramientas que necesites.
    </p>
    <p class="help">
        @if ($franjaHoy)
            Hoy el laboratorio atiende de
            <strong>{{ substr($franjaHoy[0], 0, 5) }}</strong> a
            <strong>{{ substr($franjaHoy[1], 0, 5) }}</strong>.
        @else
            Hoy no hay personal en jornada, así que lo que requiere acompañamiento no se puede reservar.
        @endif
    </p>

    @if ($misReservas->isNotEmpty())
        <h2>Mis próximas reservas</h2>
        <div class="panel">
            <table>
                <thead>
                    <tr><th>Equipo</th><th>Cuándo</th><th>Estado</th><th></th></tr>
                </thead>
                <tbody>
                @foreach ($misReservas as $r)
                    <tr>
                        <td>
                            {{ $r->reservable?->name ?? '—' }}
                            @if ($r->supervisor)
                                <div class="quien">acompaña {{ $r->supervisor->name }}</div>
                            @endif
                        </td>
                        <td>
                            {{ $r->starts_at->timezone(config('fabos.lab.timezone'))->format('d/m/Y H:i') }}
                            —
                            {{ $r->ends_at->timezone(config('fabos.lab.timezone'))->format('H:i') }}
                        </td>
                        <td>
                            <span class="pill {{ $r->status === 'confirmada' ? 'ok' : 'warn' }}">
                                {{ \App\Models\Reservation::ESTADOS[$r->status] ?? $r->status }}
                            </span>
                        </td>
                        <td style="text-align:right">
                            <form method="POST" action="{{ route('reservas.cancel', $r) }}">
                                @csrf
                                <button type="submit"
                                        style="margin:0;padding:.3rem .7rem;font-size:.78rem;background:transparent;color:var(--muted);border:1px solid var(--rule)">
                                    Cancelar
                                </button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @foreach ($porArea as $area => $filas)
        <h2>{{ $area }}</h2>
        <div class="cards">
            @foreach ($filas as $fila)
                @php $v = $fila['veredicto']; @endphp
                <div class="card-envoltura">
                    <a class="card" href="{{ route('reservas.show', $fila['activo']) }}">
                        {{-- La foto ayuda a reconocer la maquina de un vistazo,
                             que es justo lo que hace falta en un catalogo de 82
                             equipos con nombres parecidos. --}}
                        @if ($fila['activo']->photoUrl())
                            <img class="card-foto" src="{{ $fila['activo']->photoUrl() }}"
                                 alt="{{ $fila['activo']->name }}" loading="lazy">
                        @endif

                        <span class="pill {{ $clase($v->resultado) }}">{{ $etiqueta($v->resultado) }}</span>
                        <span class="n">{{ $fila['activo']->name }}</span>
                        <span class="m">{{ $v->motivo }}</span>
                    </a>

                    {{-- La asesoria se ofrece TAMBIEN a quien ya puede reservar:
                         estar habilitado no significa saberlo todo, y una
                         maquina nueva o un material raro se resuelven antes
                         preguntando que a base de intentos.

                         Solo se esconde si nadie esta declarado para asesorar
                         ese equipo: ahi el boton llevaria a una pagina vacia. --}}
                    @if ($fila['activo']->advisors_count > 0)
                        <a class="asesoria-icono"
                           href="{{ route('asesoria.show', $fila['activo']) }}"
                           title="Pedir asesoría sobre {{ $fila['activo']->name }}"
                           aria-label="Pedir asesoría sobre {{ $fila['activo']->name }}">
                            {{-- Birrete: alguien que enseña. Con titulo y
                                 aria-label, porque un icono solo no se explica
                                 a quien nunca ha pedido una asesoria. --}}
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"
                                 width="20" height="20" aria-hidden="true">
                                <path d="M12 4 2 9l10 5 10-5-10-5Z"/>
                                <path d="M6 11.5V16c0 1.5 2.7 3 6 3s6-1.5 6-3v-4.5"/>
                            </svg>
                        </a>
                    @endif
                </div>
            @endforeach
        </div>
    @endforeach

    <style>
        .card-envoltura { display: flex; flex-direction: column; }
        .card-foto { width: calc(100% + 2rem); margin: -1rem -1rem .75rem; aspect-ratio: 4/3;
            object-fit: cover; display: block; border-radius: .6rem .6rem 0 0;
            background: rgba(128,128,128,.1); }
        .card-envoltura .card { flex: 1; }
        .asesoria-icono { display: flex; align-items: center; justify-content: center;
            padding: .5rem; border: 1px solid rgba(15,118,110,.3); border-top: 0;
            border-radius: 0 0 .6rem .6rem; color: #0f766e; }
        .asesoria-icono:hover { background: rgba(15,118,110,.1); }
        .asesoria-icono:focus-visible { outline: 2px solid #0f766e; outline-offset: -2px; }
    </style>
@endsection
