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
                        <span class="pill {{ $clase($v->resultado) }}">{{ $etiqueta($v->resultado) }}</span>
                        <span class="n">{{ $fila['activo']->name }}</span>
                        <span class="m">{{ $v->motivo }}</span>
                    </a>

                    {{-- Solo cuando falta el certifab Y hay alguien declarado para
                         asesorar: ofrecer una asesoria que nadie puede atender
                         seria prometer en falso. --}}
                    @if (! $v->puedeReservar() && $fila['activo']->advisors_count > 0)
                        <a class="asesoria-enlace" href="{{ route('asesoria.show', $fila['activo']) }}">
                            Pedir asesoría →
                        </a>
                    @endif
                </div>
            @endforeach
        </div>
    @endforeach

    <style>
        .card-envoltura { display: flex; flex-direction: column; }
        .card-envoltura .card { flex: 1; }
        .asesoria-enlace { display: block; padding: .55rem .9rem; font-size: .85rem;
            font-weight: 700; text-align: center; border: 1px solid rgba(15,118,110,.35);
            border-top: 0; border-radius: 0 0 .6rem .6rem; color: #0f766e; }
        .asesoria-enlace:hover { background: rgba(15,118,110,.08); }
    </style>
@endsection
