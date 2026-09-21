@extends('layouts.publico')
@section('title', 'Propuesta recibida · ' . config('fabos.lab.name'))

@section('styles')
    .hecho{background:var(--surface);border:1px solid var(--rule);border-left:4px solid var(--accent);
           border-radius:8px;padding:1.6rem;max-width:44rem}
@endsection

@section('content')
<main>
    <section>
        <p class="rotulo">{{ $alianza->name }}</p>
        <h1>Quedaste propuesto</h1>

        <div class="hecho">
            <p style="margin-top:0">
                @if (session('nombre')) Gracias, {{ session('nombre') }}. @endif
                Recibimos tu propuesta para entrar a la alianza <strong>{{ $alianza->name }}</strong>
                ({{ $alianza->code }}).
            </p>
            <p style="margin-bottom:0">
                Quien lleva el proyecto ya lo sabe. Te escribimos al correo que dejaste para hablar de lo
                que pondrías y, si cuadra, entras en el acuerdo con las demás partes. Si necesitas
                corregir algo, vuelve a enviar el formulario con el mismo correo: reemplaza lo anterior.
            </p>
        </div>

        <p style="margin-top:1.6rem"><a href="{{ route('alianzas.show', $alianza) }}">← Volver a la alianza</a></p>
    </section>
</main>
@endsection
