@extends('layouts.app')
@section('title', 'Segundo factor · fabOS')

@section('content')
    <h1>Confirma que eres tú</h1>
    <p class="help">
        Escribe el código de tu aplicación de autenticación para entrar al backoffice.
    </p>

    <div class="panel" style="max-width:26rem">
        <form method="POST" action="{{ route('dosfactores.comprobar') }}">
            @csrf
            <label for="codigo">Código</label>
            <input id="codigo" name="codigo" type="text" inputmode="numeric"
                   autocomplete="one-time-code" required autofocus
                   style="font-family:ui-monospace,Consolas,monospace;font-size:1.4rem;letter-spacing:.4em;text-align:center">
            <button type="submit">Entrar</button>
        </form>

        <p class="foot" style="margin-top:1.2rem">
            ¿Perdiste el teléfono? Escribe aquí uno de tus códigos de recuperación.
        </p>
    </div>

    <p class="foot">
        <a href="{{ route('home') }}">Volver a mi cuenta</a> — el resto del sistema
        funciona con normalidad; el segundo factor solo protege la administración.
    </p>
@endsection
