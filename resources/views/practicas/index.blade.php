@extends('layouts.app')
@section('title', 'Prácticas · ' . config('fabos.lab.name'))

@section('content')
    <a class="volver" href="{{ route('publico.home') }}">← Volver al inicio</a>

    <h1 style="margin-top:.6rem">Hacer la práctica en el laboratorio</h1>

    @if ($convocatorias->isEmpty())
        {{-- Decirlo claro y con qué hacer en su lugar: una página vacía hace
             que la persona escriba un correo preguntando si el enlace falla. --}}
        <div class="panel">
            <p style="margin-top:0">
                Ahora mismo no hay ninguna convocatoria abierta.
            </p>
            <p class="help" style="margin-bottom:0">
                Las abrimos al empezar cada semestre. Vuelve a mirar por aquí, o escríbenos
                y te avisamos cuando salga la siguiente.
            </p>
        </div>
    @else
        <p class="help">
            Estas son las convocatorias abiertas. Puedes postularte estudies donde estudies:
            también recibimos practicantes de otras universidades.
        </p>

        @foreach ($convocatorias as $convocatoria)
            <div class="panel">
                <h2 style="margin-top:0">{{ $convocatoria->name }}</h2>

                @if ($convocatoria->description)
                    <p>{{ $convocatoria->description }}</p>
                @endif

                <p class="help">
                    @if ($convocatoria->closes_on)
                        Se reciben postulaciones hasta el
                        <strong>{{ $convocatoria->closes_on->format('d/m/Y') }}</strong>.
                    @endif
                    @if ($convocatoria->slots)
                        {{ $convocatoria->slots }}
                        {{ $convocatoria->slots === 1 ? 'cupo' : 'cupos' }}.
                    @endif
                </p>

                <p style="margin-bottom:0">
                    <a class="boton" href="{{ route('practicas.postular', $convocatoria) }}">
                        Postularme
                    </a>
                </p>
            </div>
        @endforeach
    @endif
@endsection
