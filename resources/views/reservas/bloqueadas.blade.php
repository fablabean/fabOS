@extends('layouts.app')
@section('title', 'Reservas bloqueadas · fabOS')

@section('content')
    @php $cuando = \App\Support\BloqueoDeReservas::hastaLegible(); @endphp
    <div style="max-width:36rem;margin:3rem auto;text-align:center">
        <div style="font-size:3rem;line-height:1">⚠️</div>
        <h1 style="margin:.6rem 0 .8rem">Las reservas están bloqueadas</h1>
        <p style="font-size:1.1rem;white-space:pre-line">{{ \App\Support\BloqueoDeReservas::motivo() }}</p>
        <p style="color:var(--muted,#666)">
            Mientras tanto nadie puede reservar equipos, espacios, herramientas ni asesorías.
            {{ $cuando ? 'Se reabren el ' . $cuando . '.' : 'Avisaremos cuando se reabran.' }}
        </p>
        <p style="margin-top:1.6rem"><a href="{{ route('home') }}">Volver a mi cuenta</a></p>
    </div>
@endsection
