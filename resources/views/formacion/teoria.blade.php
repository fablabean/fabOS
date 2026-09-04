@extends('layouts.app')
@section('title', $leccion->title . ' · ' . $curso->name . ' · fabOS')

@section('content')
    <p class="rotulo">{{ $curso->name }}</p>

    {{-- Dónde va: sin esto, seis pantallas seguidas se sienten interminables. --}}
    <p class="help" style="margin-bottom:.4rem">Teoría · {{ $numero }} de {{ $cuantas }}</p>
    <div class="barra"><span style="width:{{ round($numero / $cuantas * 100) }}%"></span></div>

    <h1 style="margin-top:1rem">{{ $leccion->title }}</h1>

    <div class="panel leccion">
        @include('formacion._material', ['material' => $leccion->material()])
        {!! nl2br(e($leccion->body)) !!}
    </div>

    <div class="pasos">
        @if ($numero > 1)
            <a href="{{ route('formacion.teoria', [$inscripcion, $numero - 1]) }}">← Anterior</a>
        @endif

        @if ($numero < $cuantas)
            <a class="siguiente" href="{{ route('formacion.teoria', [$inscripcion, $numero + 1]) }}">
                Siguiente →
            </a>
        @elseif ($curso->tieneExamen())
            {{-- Al final de la teoría, la puerta del examen. Volver al índice a
                 buscarla es un paso que no decide nada. --}}
            <a class="siguiente" href="{{ route('formacion.examen', $inscripcion) }}">
                Hacer el examen →
            </a>
        @else
            <a class="siguiente" href="{{ route('home') }}">Terminar</a>
        @endif
    </div>

    <style>
        .barra{height:.35rem;border-radius:99px;background:rgba(128,128,128,.25);overflow:hidden}
        .barra span{display:block;height:100%;background:#0f766e}
        .leccion{line-height:1.65}
        .pasos{display:flex;justify-content:space-between;align-items:center;
               gap:1rem;margin-top:1.2rem}
        .pasos .siguiente{margin-left:auto;font-weight:700}
    </style>
@endsection
