@extends('layouts.app')
@section('title', 'App de autenticación · fabOS')

@section('content')
    <h1>Entrar con una app de autenticación</h1>

    @if (session('status'))
        <p class="msg ok">{{ session('status') }}</p>
    @endif

    @if ($activa)
        <p class="help">
            Ya está activa. Cuando escribas tu correo en la pantalla de ingreso, en vez de
            enviarte nada te pediremos el código de seis dígitos que genera tu app.
        </p>

        <div class="panel" style="max-width:32rem">
            <h2 style="margin-top:0">Códigos de recuperación</h2>
            <p class="help">
                Si pierdes el teléfono, cada uno de estos sirve <strong>una sola vez</strong>
                para entrar. Guárdalos donde no estén junto al teléfono.
            </p>

            <ul style="font-family:ui-monospace,Consolas,monospace;font-size:1.05rem;
                       letter-spacing:.08em;columns:2;list-style:none;padding:0">
                @foreach ($codigos as $c)
                    <li>{{ $c }}</li>
                @endforeach
            </ul>
        </div>

        @unless ($obligada)
            <div class="panel" style="max-width:32rem">
                <h2 style="margin-top:0">Desactivarla</h2>
                <p class="help">
                    Volverías a entrar con el código al correo. Tiene sentido si cambiaste de
                    teléfono y ya no tienes la app configurada.
                </p>
                <form method="POST" action="{{ route('cuenta.app.desactivar') }}">
                    @csrf
                    <button type="submit">Desactivar la app</button>
                </form>
                @error('codigo') <p class="msg error">{{ $message }}</p> @enderror
            </div>
        @else
            <p class="foot">
                Como administras el laboratorio, la app es obligatoria y no puede desactivarse
                desde aquí.
            </p>
        @endunless

    @else
        <p class="help">
            Es la forma de entrar sin depender del correo: el código lo genera tu teléfono,
            aunque no haya señal ni llegue ningún mensaje.
        </p>

        <div class="panel" style="max-width:32rem">
            <h2 style="margin-top:0">1 · Escanea este código</h2>
            <p class="help">
                Con Google Authenticator, Authy, 1Password o la que uses.
            </p>

            <div style="margin:1rem 0">{!! $qrSvg !!}</div>

            <p class="help">
                ¿No puedes escanear? Escribe esta clave a mano:<br>
                <code style="font-size:1.05rem;letter-spacing:.1em">{{ $secreto }}</code>
            </p>

            <h2>2 · Confirma que funciona</h2>
            <p class="help">
                Escribe el código que muestra la app. Hasta que no lo confirmes no se activa,
                así que no puedes quedarte fuera por un escaneo fallido.
            </p>

            <form method="POST" action="{{ route('cuenta.app.activar') }}">
                @csrf
                <label for="codigo">Código de la app</label>
                <input id="codigo" name="codigo" type="text" inputmode="numeric"
                       autocomplete="one-time-code" required
                       style="font-family:ui-monospace,Consolas,monospace;font-size:1.4rem;letter-spacing:.4em;text-align:center">
                <button type="submit">Activar</button>
            </form>

            @error('codigo') <p class="msg error">{{ $message }}</p> @enderror
        </div>

        <div class="panel" style="max-width:32rem">
            <h2 style="margin-top:0">Guarda estos códigos de recuperación</h2>
            <p class="help">
                Si pierdes el teléfono son la única forma de entrar. Cada uno sirve una vez.
            </p>
            <ul style="font-family:ui-monospace,Consolas,monospace;font-size:1.05rem;
                       letter-spacing:.08em;columns:2;list-style:none;padding:0">
                @foreach ($codigos as $c)
                    <li>{{ $c }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <p class="foot"><a href="{{ route('home') }}">Volver a mi cuenta</a></p>
@endsection
