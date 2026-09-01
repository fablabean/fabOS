@extends('layouts.app')
@section('title', 'Resultado · ' . $curso->name . ' · fabOS')

@section('content')
    <p class="rotulo">{{ $curso->name }}</p>
    <h1>{{ $resultado['aprobado'] ? 'Aprobaste la teoría' : 'Todavía no' }}</h1>

    <div class="panel">
        <p style="margin:0;font-size:1.6rem;font-weight:700">{{ $resultado['nota'] }}%</p>
        <p class="help" style="margin:.2rem 0 0">
            El mínimo de este curso es {{ $curso->passing_score }}%.
        </p>

        @if ($resultado['aprobado'])
            <p style="margin:.8rem 0 0">
                @if ($falta = $inscripcion->queFaltaParaAprobar())
                    {{ $falta }} Habla con el equipo del laboratorio para agendarla: es lo
                    único que queda para tu certifab.
                @else
                    Ya está todo. Tu certifab queda registrado.
                @endif
            </p>
        @else
            <p style="margin:.8rem 0 0">
                Puedes repetirlo. Repasa lo que falló —está abajo, con el porqué— y vuelve a
                intentarlo cuando quieras.
            </p>
        @endif
    </div>

    {{-- Lo que falló, con su explicación. Un examen que solo dice «mal» enseña
         a adivinar, no a operar la máquina. --}}
    @if ($resultado['fallos']->isNotEmpty())
        <h2>Lo que conviene repasar</h2>

        @foreach ($resultado['fallos'] as $p)
            <div class="panel">
                <p style="margin:0 0 .4rem"><strong>{{ $p->prompt }}</strong></p>
                <p style="margin:0 0 .3rem">
                    La respuesta correcta es: <strong>{{ $p->options[$p->correct] ?? '—' }}</strong>
                </p>
                @if ($p->explanation)
                    <p class="help" style="margin:0">{{ $p->explanation }}</p>
                @endif
            </div>
        @endforeach
    @endif

    <p class="foot" style="margin-top:1rem">
        <a href="{{ route('formacion.teoria', $inscripcion) }}">← Volver a la teoría</a>
        @if (! $resultado['aprobado'])
            · <a href="{{ route('formacion.examen', $inscripcion) }}">Repetir el examen</a>
        @endif
    </p>
@endsection
