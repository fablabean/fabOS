@extends('layouts.app')
@section('title', 'Activar segundo factor · fabOS')

@section('content')
    <h1>Protege tu acceso de administración</h1>
    <p class="help">
        Tu rol permite cambiar el catálogo, los permisos y el dinero del laboratorio.
        El código del correo no alcanza para eso: hace falta algo que no viva en tu bandeja.
    </p>

    <div class="panel">
        <h2 style="margin-top:0">1 · Escanea este código</h2>
        <p class="help" style="margin-bottom:1rem">
            Con Google Authenticator, Microsoft Authenticator, 1Password o similar.
        </p>

        <div style="display:flex;gap:1.6rem;flex-wrap:wrap;align-items:flex-start">
            <div style="background:#fff;padding:.6rem;border-radius:6px">{!! $qrSvg !!}</div>
            <div>
                <p class="help" style="margin:0 0 .4rem">¿No puedes escanear? Escribe esta clave:</p>
                <p class="who" style="font-size:1rem;letter-spacing:.15em">{{ $secreto }}</p>
            </div>
        </div>
    </div>

    <div class="panel">
        <h2 style="margin-top:0">2 · Guarda tus códigos de recuperación</h2>
        <p class="help">
            Si pierdes el teléfono, son la única forma de entrar. Cada uno sirve
            <strong>una vez</strong>. Guárdalos donde no estén junto al teléfono.
        </p>
        <div class="who" style="columns:2;font-size:.95rem;line-height:2">
            @foreach ($codigos as $c)
                <div>{{ $c }}</div>
            @endforeach
        </div>
    </div>

    <div class="panel">
        <h2 style="margin-top:0">3 · Confirma</h2>
        <form method="POST" action="{{ route('dosfactores.activar') }}">
            @csrf
            <label for="codigo">Código de tu aplicación</label>
            <input id="codigo" name="codigo" type="text" inputmode="numeric"
                   autocomplete="one-time-code" required autofocus
                   style="font-family:ui-monospace,Consolas,monospace;font-size:1.4rem;letter-spacing:.4em;text-align:center">
            <button type="submit">Activar segundo factor</button>
        </form>
    </div>
@endsection
