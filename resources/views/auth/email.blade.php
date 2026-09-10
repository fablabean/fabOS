@extends('layouts.shell')
@section('title', 'Ingresar · fabOS')

@section('content')
    <h1>Ingresa con tu correo</h1>
    <p class="help">
        Te enviamos un código de {{ config('fabos.otp.length') }} dígitos. No necesitas contraseña.
    </p>

    <form method="POST" action="{{ route('login.send') }}">
        @csrf
        @php $dominio = config('fabos.identity.institutional_domain'); @endphp
        <label for="email">{{ $dominio ? 'Correo o nick' : 'Correo' }}</label>
        {{-- Tipo texto y no email: con el nick a secas («ehansen») el
             navegador rechazaría el envío antes de que llegue al servidor,
             que es quien le pone la arroba y el dominio. --}}
        <input id="email" name="email" type="text" inputmode="email" autocomplete="username"
               required autofocus spellcheck="false" autocapitalize="none"
               placeholder="{{ $dominio ? 'ehansen o ehansen@' . $dominio : 'nombre@correo.com' }}"
               value="{{ old('email') }}">
        @if ($dominio)
            <p class="help" style="margin-top:.35rem;font-size:.85rem">
                Si eres de la Universidad, con lo que va antes de la arroba basta: le ponemos @{{ $dominio }}.
            </p>
        @endif
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
