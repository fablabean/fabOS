@extends('layouts.publico')
@section('title', 'Demasiados intentos · fabOS')

{{-- El 429 de serie dice «Too Many Requests» en inglés y nada más. Quien lo
     ve está a mitad de un formulario y necesita saber que no perdió nada y
     cuánto esperar. --}}
@section('content')
    <p class="rotulo">Un momento</p>
    <h1>Demasiados intentos seguidos</h1>

    <p class="help">
        Desde esta conexión se enviaron muchos formularios en poco tiempo, y el sistema
        frena para protegerse del correo basura. No perdiste nada: espera unos minutos y
        vuelve a intentarlo.
    </p>

    <p class="help">
        Si te pasa en la universidad, es porque todo el campus sale a internet con la misma
        dirección y el límite se comparte. Si sigue pasando, escríbele a la coordinación del
        laboratorio.
    </p>

    <p class="foot"><a href="{{ url()->previous() !== url()->current() ? url()->previous() : url('/') }}">← Volver</a></p>
@endsection
