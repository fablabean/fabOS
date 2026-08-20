@extends('layouts.shell')
@section('title', 'Código de ingreso · fabOS')

@section('content')
    <h1>Escribe el código</h1>
    <p class="help">
        Lo enviamos a <span class="who">{{ $email }}</span>.
        Vence en {{ config('fabos.otp.ttl_minutes') }} minutos.
    </p>

    <form method="POST" action="{{ route('login.verify') }}">
        @csrf
        <input type="hidden" name="email" value="{{ $email }}">

        <label for="code">Código</label>
        <input id="code" name="code" class="code" type="text" inputmode="numeric"
               autocomplete="one-time-code" pattern="[0-9]*"
               maxlength="{{ config('fabos.otp.length') }}" required autofocus>

        <button type="submit">Entrar</button>
    </form>

    <p class="foot">
        ¿No llegó? <a href="{{ route('login') }}">Solicitar otro código</a>
    </p>
@endsection
