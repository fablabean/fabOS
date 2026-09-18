@extends('layouts.app')
@section('title', 'Reservar herramientas · ' . config('fabos.lab.name'))

@php
    use App\Services\Booking\Eligibility;

    $tz = config('fabos.lab.timezone');
    $puedenTodas = $herramientas->every(fn ($h) => $veredictos[$h->id]->puedeReservar());
    $noPuede = $herramientas->reject(fn ($h) => $veredictos[$h->id]->puedeReservar());
    $listaDeReservas = route('publico.reservas', ['modo' => 'herramientas']);
@endphp

@section('content')
    <a class="volver" href="{{ $listaDeReservas }}">← Volver a las herramientas</a>

    <h1 style="margin-top:.6rem">
        {{ $herramientas->count() === 1 ? 'Reservar una herramienta' : 'Reservar ' . $herramientas->count() . ' herramientas' }}
    </h1>
    <p class="help">
        A la misma hora, en una sola reserva. Hasta {{ $tope }} por reserva.
    </p>

    {{-- Cada una con su veredicto, ANTES de elegir hora: enterarse al enviar
         de que a una le falta el certifab es lo que hace que la gente pida de
         una en una. --}}
    <div class="panel">
        <ul class="lista">
            @foreach ($herramientas as $h)
                @php $v = $veredictos[$h->id]; @endphp
                <li>
                    <div>
                        <b>{{ $h->name }}</b>
                        <span class="help">
                            {{ $h->riskFamily?->name ?? $h->area?->name }}
                            @if ($h->puede_salir) · portátil @elseif ($h->space) · en {{ $h->space->name }} @endif
                        </span>
                    </div>
                    <div class="lado">
                        <span class="pill {{ ['success' => 'ok', 'warning' => 'warn'][$v->color()] ?? 'bad' }}">
                            {{ match ($v->resultado) {
                                Eligibility::AUTONOMO        => 'Puedes',
                                Eligibility::CON_ACOMPANANTE => 'Con acompañamiento',
                                default                      => 'Todavía no',
                            } }}
                        </span>
                        <a class="quitar" href="{{ route('reservas.herramientas', ['h' => $herramientas->where('id', '!=', $h->id)->pluck('id')->all()]) }}"
                           title="Quitar de la reserva">quitar</a>
                    </div>
                    @unless ($v->puedeReservar())
                        <p class="help motivo">{{ $v->motivo }}</p>
                    @endunless
                </li>
            @endforeach
        </ul>

        @if ($herramientas->count() < $tope)
            <p class="help" style="margin:.8rem 0 0">
                <a href="{{ route('publico.reservas', ['modo' => 'herramientas', 'h' => $herramientas->pluck('id')->all()]) }}">
                    + Añadir otra
                </a>
                (caben {{ $tope - $herramientas->count() }} más)
            </p>
        @endif
    </div>

    @if (! $puedenTodas)
        <div class="panel" style="border-left:4px solid var(--warn)">
            <p style="margin:0">
                <strong>{{ $noPuede->count() === 1 ? 'Una de ellas no se puede pedir todavía' : $noPuede->count() . ' de ellas no se pueden pedir todavía' }}:</strong>
                {{ $noPuede->pluck('name')->implode(', ') }}. Quítala de la lista para reservar las demás,
                o consigue primero el certifab con una
                <a href="{{ route('publico.reservas', ['modo' => 'asesoria']) }}">asesoría</a>.
            </p>
        </div>
    @else
        @if ($errors->any())
            <div class="panel" style="border-left:4px solid var(--error)">
                <ul style="margin:0;padding-left:1.1rem">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="panel">
            <h2 style="margin-top:0">Elegir horario</h2>

            <form method="POST" action="{{ route('reservas.herramientas.store') }}" class="agenda">
                @csrf
                @foreach ($herramientas as $h)
                    <input type="hidden" name="h[]" value="{{ $h->id }}">
                @endforeach

                <div class="agenda-campo">
                    <label for="fecha">Fecha</label>
                    <input id="fecha" name="fecha" type="date" required
                           min="{{ now($tz)->format('Y-m-d') }}"
                           value="{{ old('fecha', now($tz)->format('Y-m-d')) }}">
                </div>

                <div class="agenda-campo">
                    <label for="inicio">Hora de inicio</label>
                    <input id="inicio" name="inicio" type="time" required step="900"
                           value="{{ old('inicio', $franjaHoy ? substr($franjaHoy[0], 0, 5) : '09:00') }}">
                </div>

                <div class="agenda-campo">
                    <label for="duracion">Duración</label>
                    <select id="duracion" name="duracion" required>
                        {{-- Solo lo que todas admiten: la más exigente en mínimo y la
                             más corta en máximo mandan sobre el conjunto. --}}
                        @foreach ([30, 60, 90, 120, 180, 240, 360, 480, 720] as $min)
                            @if ($min >= $minMinutos && $min <= $maxMinutos)
                                <option value="{{ $min }}" @selected(old('duracion') == $min)>
                                    @php $hh = intdiv($min, 60); $mm = $min % 60; @endphp
                                    {{ $hh === 0 ? $mm . ' minutos' : $hh . ' hora' . ($hh > 1 ? 's' : '') . ($mm ? ' ' . $mm . ' min' : '') }}
                                </option>
                            @endif
                        @endforeach
                    </select>
                </div>

                <div class="agenda-campo agenda-ancho">
                    <label for="proposito">¿Para qué? <span style="text-transform:none;letter-spacing:0">(opcional)</span></label>
                    <input id="proposito" name="proposito" type="text" maxlength="500"
                           placeholder="Armar la maqueta de la entrega" value="{{ old('proposito') }}">
                </div>

                <div class="agenda-campo agenda-boton">
                    <button type="submit">Reservar {{ $herramientas->count() === 1 ? '' : 'las ' . $herramientas->count() }}</button>
                </div>
            </form>

            <p class="help" style="margin:.9rem 0 0">
                Se reservan juntas y se cancelan juntas. Si alguna necesita visto bueno, el
                conjunto entero queda como solicitud hasta que lo den.
            </p>
        </div>
    @endif

    <style>
        .lista{list-style:none;margin:0;padding:0}
        .lista li{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:.4rem .8rem;
                  padding:.7rem 0;border-top:1px solid var(--rule)}
        .lista li:first-child{border-top:0;padding-top:0}
        .lista b{display:block}
        .lista .lado{display:flex;align-items:center;gap:.8rem}
        .lista .quitar{font-size:.8rem;color:var(--muted)}
        .lista .motivo{flex-basis:100%;margin:0}

        .agenda { display: grid; gap: .75rem 1rem; align-items: end; }
        .agenda-campo { display: flex; flex-direction: column; gap: .3rem; margin: 0; }
        .agenda-campo > label { margin: 0; }
        .agenda-campo > input, .agenda-campo > select { margin: 0; width: 100%; }
        .agenda-boton { align-self: end; }
        .agenda-boton > button { margin: 0; width: 100%; }
        @media (min-width: 720px) {
            .agenda { grid-template-columns: repeat(3, 1fr); }
            .agenda-ancho { grid-column: span 2; }
        }
    </style>
@endsection
