@extends('layouts.publico')
@section('title', 'Preinscripción recibida · ' . config('fabos.lab.name'))

@php
    $faltan = $cohorte?->faltanParaAbrir();
    $enlace = route('preinscripcion', $curso);
    $mensaje = 'Me preinscribí a ' . $curso->name . ' en ' . config('fabos.lab.name')
        . ($faltan ? '. Faltan ' . $faltan . ' para que abran la cohorte' : '') . ': ' . $enlace;
@endphp

@section('styles')
    .hecho{background:var(--surface);border:1px solid var(--rule);border-left:4px solid var(--accent);
           border-radius:8px;padding:1.6rem;max-width:44rem}
    .compartir{display:flex;flex-wrap:wrap;gap:.6rem;margin-top:1rem}
    .btn.suave{background:transparent;color:var(--accent);border:1px solid var(--accent)}
@endsection

@section('content')
<main>
    <section>
        <p class="rotulo">{{ $curso->name }}</p>
        <h1>Quedaste en la lista</h1>

        <div class="hecho">
            <p style="margin-top:0">
                @if (session('nombre'))
                    Gracias, {{ session('nombre') }}.
                @endif
                Recibimos tu preinscripción
                @if ($cohorte) a la cohorte <strong>{{ $cohorte->code }}</strong>@endif.
                Te mandamos un correo para que te quede constancia.
            </p>

            {{-- Decir qué pasa ahora: sin esto, la pregunta llega por correo a
                 los tres días y hay que responderla una por una. --}}
            <p>
                No pagas nada todavía y no quedas comprometido. Cuando decidamos si la cohorte
                abre te escribimos al correo que dejaste, sea cual sea la respuesta. Si necesitas
                corregir algo, vuelve a enviar el formulario con el mismo correo: reemplaza lo
                anterior en vez de duplicarlo.
            </p>

            @if ($faltan)
                {{-- El mejor momento para pedir que lo comparta es este: acaba de
                     decidir que le interesa, y sabe que depende de ser suficientes. --}}
                <p style="margin-bottom:0">
                    <strong>
                        {{ $faltan === 1 ? 'Falta una persona' : 'Faltan ' . $faltan . ' personas' }}
                        para abrir la cohorte.
                    </strong>
                    Si conoces a alguien a quien le sirva, cuéntale: es lo que más ayuda a que se abra.
                </p>

                <div class="compartir">
                    <a class="btn" target="_blank" rel="noopener"
                       href="https://wa.me/?text={{ rawurlencode($mensaje) }}">Compartir por WhatsApp</a>
                    <a class="btn suave"
                       href="mailto:?subject={{ rawurlencode($curso->name . ' en ' . config('fabos.lab.name')) }}&body={{ rawurlencode($mensaje) }}">Enviar por correo</a>
                </div>
            @endif
        </div>

        <p style="margin-top:1.6rem"><a href="{{ $enlace }}">← Volver a la página del programa</a></p>
    </section>
</main>
@endsection
