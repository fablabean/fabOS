@extends('layouts.app')
@section('title', 'Examen · ' . $curso->name . ' · fabOS')

@section('content')
    <p class="rotulo">{{ $curso->name }}</p>
    <h1>Examen teórico</h1>

    <p class="help">
        {{ $preguntas->count() }} preguntas. Se aprueba con <strong>{{ $curso->passing_score }}%</strong>,
        así que puedes fallar
        {{ (int) floor($preguntas->count() * (100 - $curso->passing_score) / 100) }}.
        @if ($inscripcion->theory_attempts > 0)
            Llevas {{ $inscripcion->theory_attempts }}
            {{ $inscripcion->theory_attempts === 1 ? 'intento' : 'intentos' }}; puedes repetirlo.
        @endif
    </p>

    @error('examen') <p class="msg error">{{ $message }}</p> @enderror

    <form method="POST" action="{{ route('formacion.calificar', $inscripcion) }}">
        @csrf

        @foreach ($preguntas as $i => $p)
            <div class="panel">
                <p style="margin:0 0 .7rem"><strong>{{ $i + 1 }}.</strong> {{ $p['prompt'] }}</p>

                @foreach ($p['options'] as $n => $opcion)
                    <label class="opcion">
                        <input type="radio" name="respuestas[{{ $p['id'] }}]" value="{{ $n }}" required>
                        <span>{{ $opcion }}</span>
                    </label>
                @endforeach
            </div>
        @endforeach

        <button type="submit">Entregar el examen</button>
    </form>

    <style>
        .opcion{display:flex;gap:.6rem;align-items:baseline;padding:.35rem 0;cursor:pointer}
        .opcion input{margin:0}
    </style>
@endsection
