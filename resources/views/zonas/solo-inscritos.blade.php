@extends('layouts.app')
@section('title', $modulo->titulo . ' · ' . config('fabos.lab.name'))

@section('content')
    <a class="volver" href="{{ route('zonas.inicio', $modulo->zona) }}">← {{ $modulo->zona->nombre }}</a>

    <h1 style="margin-top:.6rem">{{ $modulo->titulo }}</h1>

    <div class="panel" style="border-left:4px solid var(--warn)">
        <p style="margin:0">
            Este material es para quienes están inscritos
            @if ($modulo->curso)
                en <a href="{{ route('formacion') }}">{{ $modulo->curso->name }}</a>.
            @else
                en el curso al que pertenece.
            @endif
            Si ya te inscribiste y no lo ves, escríbenos.
        </p>
    </div>
@endsection
