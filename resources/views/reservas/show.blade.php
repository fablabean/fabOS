@extends('layouts.app')
@section('title', $activo->name . ' · fabOS')

@php
    use App\Services\Booking\Eligibility;

    $clase = match ($veredicto->resultado) {
        Eligibility::AUTONOMO        => 'ok',
        Eligibility::CON_ACOMPANANTE => 'warn',
        default                      => 'bad',
    };
    $tz = config('fabos.lab.timezone');
@endphp

@section('content')
    <a class="volver" href="{{ route('reservas.index') }}">← Volver al catálogo</a>

    <h1 style="margin-top:.6rem">{{ $activo->name }}</h1>
    <p class="help">
        {{ $activo->area?->name }}
        @if ($activo->riskFamily) · {{ $activo->riskFamily->name }} @endif
    </p>

    <div class="panel">
        <span class="pill {{ $clase }}">
            {{ match ($veredicto->resultado) {
                Eligibility::AUTONOMO        => 'Puedes reservar',
                Eligibility::CON_ACOMPANANTE => 'Con acompañamiento',
                default                      => 'Todavía no',
            } }}
        </span>
        <p style="margin:.3rem 0 0">{{ $veredicto->motivo }}</p>

        {{-- El «todavía no» no cierra la puerta: indica el camino (§10). --}}
        @if ($veredicto->faltantes)
            <p style="margin:.9rem 0 0;font-weight:600">Para habilitarte necesitas:</p>
            <ul class="falta">
                @foreach ($veredicto->faltantes as $f)
                    <li>{{ $f }}</li>
                @endforeach
            </ul>
            @if ($activo->advisors_count > 0)
                <p class="help" style="margin-top:.9rem">
                    Puedes empezar por una asesoría: alguien del equipo te acompaña y te
                    muestra cómo se usa. No necesitas el certifab para pedirla.
                </p>
                <p style="margin-top:.75rem">
                    <a class="btn" href="{{ route('asesoria.show', $activo) }}">Pedir asesoría</a>
                </p>
            @else
                {{-- Sin nadie declarado para asesorar, el sistema no tendria a quien
                     asignarla: se dice la verdad en vez de ofrecer un boton muerto. --}}
                <p class="help" style="margin-top:.9rem">
                    Escribe a la coordinación para agendar una asesoría de este equipo.
                    Al terminarla quedas habilitado y podrás reservar por tu cuenta.
                </p>
            @endif
        @endif

        @if ($veredicto->requierePresencia())
            <p class="help" style="margin:.9rem 0 0">
                Al reservar se asigna un colaborador certificado que esté en jornada,
                y se reserva también su tiempo.
            </p>
        @endif
    </div>

    @if ($veredicto->puedeReservar())
        <div class="panel">
            <h2 style="margin-top:0">Elegir horario</h2>

            <form method="POST" action="{{ route('reservas.store', $activo) }}">
                @csrf

                <label for="fecha">Fecha</label>
                <input id="fecha" name="fecha" type="date" required
                       min="{{ now($tz)->format('Y-m-d') }}"
                       value="{{ old('fecha', now($tz)->format('Y-m-d')) }}">

                <label for="inicio">Hora de inicio</label>
                <input id="inicio" name="inicio" type="time" required step="900"
                       value="{{ old('inicio', $franjaHoy ? substr($franjaHoy[0], 0, 5) : '09:00') }}">

                <label for="duracion">Duración</label>
                <select id="duracion" name="duracion" required>
                    @foreach ([30, 60, 90, 120, 180, 240, 360, 480, 720] as $min)
                        @if ($min >= $activo->min_minutes && $min <= $activo->max_minutes)
                            <option value="{{ $min }}" @selected(old('duracion') == $min)>
                                {{ $min < 60 ? $min . ' minutos' : intdiv($min, 60) . ' hora' . (intdiv($min, 60) > 1 ? 's' : '') }}
                                @if ($veredicto->maxMinutos && $min > $veredicto->maxMinutos)
                                    — requiere visto bueno
                                @endif
                            </option>
                        @endif
                    @endforeach
                </select>

                <label for="proposito">¿Para qué? <span style="text-transform:none;letter-spacing:0">(opcional)</span></label>
                <input id="proposito" name="proposito" type="text" maxlength="500"
                       placeholder="Prototipo de la clase de diseño" value="{{ old('proposito') }}">

                <button type="submit">Reservar</button>
            </form>
        </div>
    @endif

    {{-- ------------------------------------------------- cuánto cuesta (§12) --}}
    @if ($cotizacion->totalMenor > 0)
        @php
            $moneda = config('fabos.currency.code');
            $unidades = config('fabos.currency.minor_units');
            $horas = intdiv($minutosCotizados, 60);
        @endphp

        <div class="panel">
            <h2 style="margin-top:0">Cuánto cuesta</h2>
            <p class="help" style="margin-top:0">
                Estimado para {{ $horas ? $horas . ($horas > 1 ? ' horas' : ' hora') : $minutosCotizados . ' minutos' }}
                de uso. Al cerrar se cobra el tiempo real y la diferencia vuelve a tu saldo.
            </p>

            <table>
                <tbody>
                @foreach ($cotizacion->lineas as $l)
                    <tr>
                        <th style="font-weight:500">
                            {{ $l['concepto'] }}
                            @if ($l['detalle'])
                                <div class="quien">{{ $l['detalle'] }}</div>
                            @endif
                        </th>
                        <td style="text-align:right;white-space:nowrap">
                            {{ number_format($l['importe'] / $unidades, 2, ',', '.') }}
                        </td>
                    </tr>
                @endforeach
                <tr>
                    <th>Total estimado</th>
                    <td style="text-align:right;white-space:nowrap;font-weight:700">
                        {{ number_format($cotizacion->total(), 2, ',', '.') }} {{ $moneda }}
                    </td>
                </tr>
                @if ($cotizacion->depositoMenor > 0)
                    <tr>
                        <th style="font-weight:500">
                            Se retiene al reservar
                            <div class="quien">garantía; el resto se cobra al cerrar</div>
                        </th>
                        <td style="text-align:right;white-space:nowrap">
                            {{ number_format($cotizacion->deposito(), 2, ',', '.') }}
                        </td>
                    </tr>
                @endif
                </tbody>
            </table>

            <p class="foot" style="margin-top:.9rem">
                Tu saldo: <strong>{{ number_format($saldo / $unidades, 2, ',', '.') }} {{ $moneda }}</strong>.
                @unless (\App\Support\Settings::cobrosActivos())
                    Los cobros están apagados: por ahora reservar no descuenta nada.
                @endunless
                @if ($cotizacion->esSupuesta)
                    Esta tarifa es provisional, pendiente de aprobación de la coordinación.
                @endif
            </p>
        </div>
    @endif

    {{-- ------------------------------------------------- lista de espera --}}
    <div class="panel">
        <h2 style="margin-top:0">¿Está siempre lleno?</h2>

        @if ($miEspera)
            <p style="margin:0">
                Estás en la lista de espera para
                <strong>{{ $miEspera->wants_from->timezone($tz)->format('d/m/Y') }}</strong>
                a <strong>{{ $miEspera->wants_until->timezone($tz)->format('d/m/Y') }}</strong>.
                Te avisamos si alguien suelta una franja dentro de esas fechas.
            </p>

            <form method="POST" action="{{ route('reservas.espera.salir', $miEspera) }}">
                @csrf
                <button type="submit"
                        style="background:transparent;color:var(--muted);border:1px solid var(--rule)">
                    Ya no me interesa
                </button>
            </form>
        @else
            <p class="help" style="margin-top:0">
                Dinos entre qué fechas te sirve y te avisamos si alguien cancela. No reserva
                nada: el hueco queda para quien lo tome primero.
            </p>

            <form method="POST" action="{{ route('reservas.esperar', $activo) }}">
                @csrf

                <label for="espera-desde">Desde</label>
                <input id="espera-desde" name="desde" type="date" required
                       min="{{ now($tz)->format('Y-m-d') }}"
                       value="{{ now($tz)->format('Y-m-d') }}">

                <label for="espera-hasta">Hasta</label>
                <input id="espera-hasta" name="hasta" type="date" required
                       min="{{ now($tz)->format('Y-m-d') }}"
                       value="{{ now($tz)->addWeeks(2)->format('Y-m-d') }}">

                <label for="espera-nota">Algo que debamos saber <span style="text-transform:none;letter-spacing:0">(opcional)</span></label>
                <input id="espera-nota" name="nota" type="text" maxlength="255"
                       placeholder="Solo puedo en la mañana">

                <button type="submit">Avísenme si se libera</button>
            </form>
        @endif
    </div>

    <div class="panel">
        <h2 style="margin-top:0">Condiciones del equipo</h2>
        <table>
            <tr>
                <th>Reserva mínima</th>
                <td>{{ $activo->min_minutes }} minutos</td>
            </tr>
            <tr>
                <th>Sin visto bueno</th>
                <td>
                    {{ $activo->autonomous_minutes
                        ? intdiv($activo->autonomous_minutes, 60) . ' h ' . ($activo->autonomous_minutes % 60 ?: '') . ' min'
                        : 'siempre requiere visto bueno' }}
                </td>
            </tr>
            <tr>
                <th>Máximo</th>
                <td>{{ intdiv($activo->max_minutes, 60) }} horas</td>
            </tr>
            @if ($activo->unattended_use)
                <tr>
                    <th>Uso desatendido</th>
                    <td>El trabajo puede correr sin que estés presente.</td>
                </tr>
            @endif
            @if ($activo->dependencies->isNotEmpty())
                <tr>
                    <th>Requiere operativo</th>
                    <td>{{ $activo->dependencies->pluck('name')->implode(', ') }}</td>
                </tr>
            @endif
            @if ($activo->riskFamily?->safety_notes)
                <tr>
                    <th>Seguridad</th>
                    <td>{{ $activo->riskFamily->safety_notes }}</td>
                </tr>
            @endif
        </table>
    </div>
@endsection
