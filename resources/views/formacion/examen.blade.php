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
                @include('formacion._material', ['material' => $p['material']])

                @foreach ($p['options'] as $o)
                    <label class="opcion">
                        <input type="radio" name="respuestas[{{ $p['id'] }}]" value="{{ $o['n'] }}" required>
                        <span>{{ $o['texto'] }}</span>
                    </label>
                @endforeach
            </div>
        @endforeach

        <button type="submit">Entregar el examen</button>
    </form>

    <style>
        /* En rejilla y no en flex: asi el redondel ocupa una columna fija
           pegada a la izquierda, y un texto de dos lineas sigue alineado
           consigo mismo en vez de meterse debajo del circulito. */
        .opcion{display:grid;grid-template-columns:1.05rem 1fr;gap:.65rem;
                align-items:start;padding:.4rem 0;cursor:pointer;line-height:1.45}
        .opcion input{margin-top:.15rem}
    </style>
@endsection
