@extends('layouts.app')
@section('title', 'Otra opción para tu reserva · ' . config('fabos.lab.name'))

{{--
    Tomar la opción que se le propuso (§10). Abrir el enlace no reserva: los
    correos abren enlaces solos para la vista previa. Reserva el botón.
--}}
@section('content')
    <div class="panel" style="max-width:36rem;margin:1.5rem auto">
        <h1 style="margin-top:0">Otra opción para tu reserva</h1>

        @if (session('status'))
            <p class="msg ok">{{ session('status') }}</p>
        @endif
        @if (session('error'))
            <p class="msg error">{{ session('error') }}</p>
        @endif

        <p>
            Pediste <strong>{{ $reserva->nombreDelRecurso() }}</strong> para el {{ $pedida }}.
            Te propusimos:
        </p>
        <p style="font-size:1.15rem"><strong>{{ $cuando }}</strong></p>

        @if (! session('status'))
            @if ($abierta)
                <form method="POST" action="{{ $accion }}">
                    @csrf
                    <button type="submit">Tomar esta opción</button>
                </form>
                <p class="foot" style="margin-top:.6rem">Queda reservada a tu nombre y te llega la confirmación por correo.</p>
            @elseif ($tomada === $opcion)
                <p class="msg ok">Esta es la opción que tomaste: tu reserva está confirmada.</p>
            @else
                <p class="msg">Esta solicitud ya no está abierta. Si todavía la necesitas, responde el correo o pídela de nuevo.</p>
            @endif
        @endif
    </div>
@endsection
