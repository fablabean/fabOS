@extends('layouts.app')
@section('title', $activo->name . ' · fabOS')

@php
    use App\Services\Booking\Eligibility;
    $tz = config('fabos.lab.timezone');
@endphp

@section('content')
    <h1>{{ $activo->name }}</h1>
    <p class="help">
        {{ $activo->area?->name }}
        @if ($activo->riskFamily) · {{ $activo->riskFamily->name }} @endif
        · <span class="pill {{ $activo->status === 'operativo' ? 'ok' : 'bad' }}">
            {{ \App\Models\Asset::ESTADOS[$activo->status] ?? $activo->status }}
        </span>
    </p>

    @if ($reserva && $reserva->status === 'en_curso')
        {{-- Está usando el equipo ahora mismo. --}}
        <div class="panel">
            <h2 style="margin-top:0">Estás usando este equipo</h2>
            <p>
                Llegaste a las
                <strong>{{ $reserva->checked_in_at->timezone($tz)->format('H:i') }}</strong>,
                y tu bloque va hasta las
                <strong>{{ $reserva->ends_at->timezone($tz)->format('H:i') }}</strong>.
            </p>
            <p class="help">
                Al terminar, cierra aquí para liberar el equipo. Si te vas antes,
                alguien más puede aprovechar el tiempo que sobra.
            </p>
            <form method="POST" action="{{ route('escaneo.checkout', $reserva) }}">
                @csrf

                {{-- El material se declara al cerrar, no al reservar: nadie sabe
                     de antemano cuántos gramos va a gastar. --}}
                @if ($insumos->isNotEmpty())
                    <p style="margin:1.2rem 0 .4rem;font-weight:600">¿Usaste material?</p>
                    <p class="help" style="margin:0 0 .8rem">
                        Solo lo que gastaste. Sale del inventario y se suma a lo que pagas.
                    </p>

                    @foreach ($insumos as $insumo)
                        <label for="material-{{ $insumo->id }}">
                            {{ $insumo->name }} ({{ $insumo->unit }})
                        </label>
                        <input id="material-{{ $insumo->id }}"
                               name="material[{{ $insumo->id }}]"
                               type="number" step="0.001" min="0" inputmode="decimal"
                               placeholder="0">
                    @endforeach
                @endif

                <button type="submit">Terminé, liberar el equipo</button>
            </form>
        </div>

    @elseif ($reserva)
        {{-- Tiene reserva confirmada pendiente de llegada. --}}
        <div class="panel">
            <h2 style="margin-top:0">Tienes una reserva aquí</h2>
            <p>
                De <strong>{{ $reserva->starts_at->timezone($tz)->format('H:i') }}</strong>
                a <strong>{{ $reserva->ends_at->timezone($tz)->format('H:i') }}</strong>
                del {{ $reserva->starts_at->timezone($tz)->format('d/m/Y') }}.
                @if ($reserva->supervisor)
                    Te acompaña {{ $reserva->supervisor->name }}.
                @endif
            </p>
            <p class="help">
                Registra tu llegada para empezar. Si no llegas dentro de
                {{ config('fabos.checkin.tolerancia') }} minutos, la reserva se libera
                para que el equipo no quede bloqueado.
            </p>
            <form method="POST" action="{{ route('escaneo.checkin', $reserva) }}">
                @csrf
                <button type="submit">Registrar mi llegada</button>
            </form>
        </div>

    @else
        {{-- Sin reserva: se le dice si podría tenerla. --}}
        <div class="panel">
            <span class="pill {{ match ($veredicto->resultado) {
                Eligibility::AUTONOMO        => 'ok',
                Eligibility::CON_ACOMPANANTE => 'warn',
                default                      => 'bad',
            } }}">
                {{ match ($veredicto->resultado) {
                    Eligibility::AUTONOMO        => 'Puedes reservarlo',
                    Eligibility::CON_ACOMPANANTE => 'Con acompañamiento',
                    default                      => 'Todavía no',
                } }}
            </span>
            <p style="margin:.3rem 0 0">{{ $veredicto->motivo }}</p>

            @if ($veredicto->faltantes)
                <ul class="falta">
                    @foreach ($veredicto->faltantes as $f)
                        <li>{{ $f }}</li>
                    @endforeach
                </ul>
            @endif

            @if ($veredicto->puedeReservar())
                <a href="{{ route('reservas.show', $activo) }}">
                    <button type="button">Reservar este equipo</button>
                </a>
            @endif
        </div>
    @endif

    {{-- Órdenes abiertas: quien llega debe saber si el equipo está intervenido. --}}
    @if ($ordenes->isNotEmpty())
        <div class="panel">
            <h2 style="margin-top:0">Mantenimiento en curso</h2>
            @foreach ($ordenes as $o)
                <p class="help" style="margin:0 0 .4rem">
                    <span class="pill {{ $o->stops_equipment ? 'bad' : 'warn' }}">
                        {{ \App\Models\WorkOrder::TIPOS[$o->kind] ?? $o->kind }}
                    </span>
                    {{ $o->reported_issue }}
                    <span class="quien">
                        · reportado {{ $o->created_at->timezone($tz)->diffForHumans() }}
                    </span>
                </p>
            @endforeach
        </div>
    @endif

    {{-- Reportar una falla: quien detecta el problema es quien está delante. --}}
    <div class="panel">
        <h2 style="margin-top:0">¿Algo anda mal?</h2>
        <form method="POST" action="{{ route('escaneo.falla', $activo->qr_token) }}">
            @csrf
            <label for="problema">Cuéntanos qué pasa</label>
            <input id="problema" name="problema" type="text" required maxlength="500"
                   placeholder="La bandeja no calienta, hace un ruido raro…">

            <label style="display:flex;gap:.5rem;align-items:center;text-transform:none;letter-spacing:0;
                          font-family:inherit;font-size:.9rem;color:var(--ink-soft);margin-top:.9rem">
                <input type="checkbox" name="detiene" value="1" style="width:auto">
                No se puede usar así — sácalo de servicio
            </label>

            <button type="submit" style="background:transparent;color:var(--warn);border:1px solid var(--rule)">
                Reportar falla
            </button>
        </form>
    </div>

    <p><a class="volver" href="{{ route('reservas.index') }}">← Ver todo el catálogo</a></p>
@endsection
