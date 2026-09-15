@extends('layouts.app')
@section('title', 'Postulación recibida · ' . config('fabos.lab.name'))

@section('content')
    <a class="volver" href="{{ route('publico.home') }}">← Volver al inicio</a>

    <h1 style="margin-top:.6rem">Quedó anotada</h1>

    <div class="panel" style="border-left:4px solid var(--ok)">
        <p style="margin-top:0">
            @if (session('nombre'))
                Gracias, {{ session('nombre') }}.
            @endif
            Recibimos tu postulación a <strong>{{ $convocatoria->name }}</strong>.
        </p>

        <p class="help" style="margin-bottom:0">
            {{-- Decir qué pasa ahora y cuándo: sin esto, la pregunta llega por
                 correo a los tres días y hay que responderla una por una. --}}
            Las revisamos todas juntas cuando cierre la convocatoria
            @if ($convocatoria->closes_on)
                —el {{ $convocatoria->closes_on->format('d/m/Y') }}—
            @endif
            y te escribimos al correo que dejaste, hayas quedado o no.
            Si necesitas corregir algo, vuelve a enviar el formulario con el mismo correo:
            reemplaza lo anterior en vez de duplicarlo.
        </p>
    </div>
@endsection
