@extends('layouts.app')
@section('title', 'Falta un factor · fabOS')

@section('content')
    <h1>Falta una prueba más</h1>
    <p class="help">
        Entraste con tu aplicación de autenticación. El backoffice pide <strong>dos
        pruebas distintas</strong>, así que volver a pedirte ese mismo código no
        demostraría nada nuevo.
    </p>

    <div class="panel" style="max-width:26rem">
        @if (! $enviado)
            <p>Te enviamos un código a <strong>{{ $correo }}</strong>.</p>

            <form method="POST" action="{{ route('dosfactores.otroFactor.enviar') }}">
                @csrf
                <button type="submit">Enviarme el código</button>
            </form>
        @else
            <form method="POST" action="{{ route('dosfactores.otroFactor.comprobar') }}">
                @csrf
                <label for="codigo">Código que llegó a {{ $correo }}</label>
                <input id="codigo" name="codigo" type="text" inputmode="numeric"
                       autocomplete="one-time-code" required autofocus
                       style="font-family:ui-monospace,Consolas,monospace;font-size:1.4rem;letter-spacing:.4em;text-align:center">
                <button type="submit">Entrar</button>
            </form>
        @endif

        @error('codigo') <p class="msg error">{{ $message }}</p> @enderror
    </div>

    <p class="foot">
        ¿No llega el correo? Si tienes el carné digital a mano,
        <a href="{{ route('carnet') }}">escanéalo</a>: también cuenta como la otra prueba.
    </p>

    <p class="foot">
        <a href="{{ route('home') }}">Volver a mi cuenta</a> — el resto del sistema
        funciona con normalidad; esto solo protege la administración.
    </p>
@endsection
