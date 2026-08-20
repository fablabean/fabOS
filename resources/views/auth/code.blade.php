@extends('layouts.shell')
@section('title', 'Código de ingreso · fabOS')

@section('content')
    <h1>Escribe el código</h1>

    {{-- El texto cubre los dos casos a propósito. Si dijera «lo enviamos a tu
         correo» solo cuando toca, esta pantalla delataría quién tiene una app
         configurada —y con ello, quién tiene cuenta—. Diciendo las dos cosas,
         cada quien reconoce la suya y nadie averigua nada. --}}
    <p class="help">
        El de tu <strong>aplicación de autenticación</strong>, si tienes una.
        Si no, el que enviamos a <span class="who">{{ $email }}</span>.
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

    @error('code') <p class="msg error">{{ $message }}</p> @enderror

    @if (session('status'))
        <p class="msg ok">{{ session('status') }}</p>
    @endif

    <p class="foot">
        ¿Perdiste el teléfono, o no usas app?
    </p>

    <form method="POST" action="{{ route('login.code.enviar') }}">
        @csrf
        <input type="hidden" name="email" value="{{ $email }}">
        <button type="submit" class="secundario">Enviarme un código al correo</button>
    </form>

    <p class="foot">
        También puedes <a href="{{ route('login') }}">empezar de nuevo</a>.
    </p>
@endsection
