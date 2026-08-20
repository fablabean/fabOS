@extends('layouts.app')
@section('title', $ubicacion->name . ' · Inventario')

@php $tz = config('fabos.lab.timezone'); @endphp

@section('content')
    <h1>{{ $ubicacion->name }}</h1>
    <p class="help">
        {{ $equipos->count() }} {{ $equipos->count() === 1 ? 'equipo debería' : 'equipos deberían' }}
        estar aquí. Confirma lo que veas; lo que no toques queda como estaba.
    </p>

    @if ($equipos->isEmpty())
        <div class="panel">
            <p style="margin:0">No hay nada asignado a esta ubicación todavía.</p>
            <p class="help" style="margin:.6rem 0 0">
                Asigna equipos desde el backoffice, en Activos → Ubicación.
            </p>
        </div>
    @endif

    @foreach ($equipos as $e)
        <div class="panel" style="padding:.9rem 1.1rem">
            <div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap">
                <div style="flex:1;min-width:12rem">
                    <strong>{{ $e->name }}</strong>
                    <div class="quien">
                        {{ $e->area?->name }}
                        @if ($e->asset_tag) · {{ $e->asset_tag }} @endif
                        @if ($e->last_checked_at)
                            · revisado {{ $e->last_checked_at->timezone($tz)->diffForHumans() }}
                        @else
                            · nunca revisado
                        @endif
                    </div>
                </div>

                <div style="display:flex;gap:.4rem;flex-wrap:wrap">
                    @foreach (['presente' => 'Está', 'ausente' => 'No está'] as $valor => $etiqueta)
                        <form method="POST" action="{{ route('inventario.registrar', $e) }}">
                            @csrf
                            <input type="hidden" name="result" value="{{ $valor }}">
                            <input type="hidden" name="location_id" value="{{ $ubicacion->id }}">
                            <button type="submit" style="margin:0;padding:.45rem .9rem;font-size:.85rem;
                                {{ $valor === 'ausente' ? 'background:transparent;color:var(--bad);border:1px solid var(--rule)' : '' }}">
                                {{ $etiqueta }}
                            </button>
                        </form>
                    @endforeach
                </div>
            </div>
        </div>
    @endforeach

    {{-- Algo que apareció aquí sin estar asignado: se reubica escaneando. --}}
    @if ($aparecidos->isNotEmpty())
        <h2>Reportado en esta ubicación</h2>
        <div class="panel">
            @foreach ($aparecidos as $c)
                <div class="quien">{{ $c->asset?->name }} · {{ $c->checked_at->timezone($tz)->format('d/m/Y') }}</div>
            @endforeach
        </div>
    @endif

    <p class="foot">
        ¿Encontraste algo que no está en esta lista? Escanea el QR de ese equipo
        y regístralo desde ahí.
    </p>
@endsection
