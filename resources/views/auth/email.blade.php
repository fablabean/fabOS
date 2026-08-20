@extends('layouts.shell')
@section('title', 'Ingresar · fabOS')

@section('content')
    <h1>Ingresa con tu correo</h1>
    <p class="help">
        Te enviamos un código de {{ config('fabos.otp.length') }} dígitos. No necesitas contraseña.
    </p>

    <form method="POST" action="{{ route('login.send') }}">
        @csrf
        <label for="email">Correo</label>
        <input id="email" name="email" type="email" inputmode="email" autocomplete="email"
               {{-- "@{{" es la secuencia de escape de Blade: pegar la arroba
                    justo antes de la expresión la imprime literal. Por eso la
                    arroba se concatena dentro de la propia expresión. --}}
               required autofocus
               placeholder="{{ 'nombre@' . config('fabos.identity.institutional_domain') }}"
               value="{{ old('email') }}">
        <button type="submit">Enviarme el código</button>
    </form>

    @if (\App\Support\Settings::carnetLoginEnabled())
        <p class="foot" style="text-align:center;margin-top:1.6rem">
            o <a href="{{ route('carnet') }}">escanea tu carné digital</a>
        </p>
    @endif
    <p class="foot">
        ¿Te dieron un código en el laboratorio, o usas una app de autenticación?
        <a href="{{ route('login.code', ['email' => '']) }}"
           onclick="event.preventDefault(); const c=document.getElementById('email').value.trim(); if(c) location.href='{{ route('login.code') }}?email='+encodeURIComponent(c); else document.getElementById('email').focus();">Ya tengo un código</a>
    </p>


    <p class="foot">
        Si eres de la Universidad, usa tu correo institucional: así quedas
        vinculado con tu categoría y tu dotación de {{ config('fabos.currency.name') }}s.
    </p>
@endsection
