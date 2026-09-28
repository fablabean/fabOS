@extends('layouts.publico')
@section('title', 'Encuesta · ' . $edicion->nombre())

@section('styles')
    .hecho{background:var(--surface);border:1px solid var(--rule);border-radius:8px;padding:1.6rem;max-width:44rem}
    .pregunta{margin:0 0 1.4rem;border:0;padding:0}
    .pregunta legend,.pregunta > label{font-weight:600;display:block;margin-bottom:.5rem}
    .escala{display:flex;gap:.4rem;flex-wrap:wrap}
    .escala label{flex:1;min-width:3rem;text-align:center;border:1px solid var(--rule);border-radius:6px;padding:.6rem 0;cursor:pointer}
    .escala input{display:block;margin:0 auto .3rem}
    .escala-extremos{display:flex;justify-content:space-between;font-size:.78rem;color:var(--muted);margin-top:.3rem}
    .opcion{display:flex;gap:.5rem;align-items:center;margin:.3rem 0;font-weight:400}
    textarea{display:block;width:100%;padding:.6rem .7rem;font:inherit;box-sizing:border-box;
             background:var(--ground);color:var(--ink);border:1px solid var(--rule);border-radius:4px}
    .error{background:color-mix(in srgb,#9B2C2C 12%,transparent);border-radius:6px;padding:.8rem 1rem;margin-bottom:1rem}
@endsection

@section('content')
<main>
    <section>
        <p class="rotulo">Encuesta de satisfacción</p>
        <h1 style="font-size:clamp(1.6rem,5vw,2.4rem)">{{ $edicion->nombre() }}</h1>

        @if (session('gracias') || $respondida)
            <div class="hecho">
                <p style="margin:0;font-size:1.1rem"><strong>¡Gracias por responder!</strong></p>
                <p style="margin:.4rem 0 0">Con lo que nos cuentas mejoramos las próximas actividades.</p>
            </div>
        @elseif (! $asistio)
            <div class="hecho"><p style="margin:0">Esta encuesta es para quienes asistieron a la actividad.</p></div>
        @else
            <form method="POST" action="{{ $accion }}" class="hecho">
                @csrf

                @if ($errors->any())
                    <div class="error">{{ $errors->first() }}</div>
                @endif

                @foreach ($preguntas as $p)
                    @php $campo = 'r[' . $p->id . ']'; $viejo = old('r.' . $p->id); @endphp

                    @if ($p->type === 'escala')
                        <fieldset class="pregunta">
                            <legend>{{ $p->label }}@if ($p->required) <span style="color:#9B2C2C">*</span>@endif</legend>
                            <div class="escala">
                                @foreach (range(1, 5) as $n)
                                    <label><input type="radio" name="{{ $campo }}" value="{{ $n }}" @required($p->required) @checked((string) $viejo === (string) $n)>{{ $n }}</label>
                                @endforeach
                            </div>
                            <div class="escala-extremos"><span>1 · Muy insatisfecho</span><span>5 · Muy satisfecho</span></div>
                        </fieldset>
                    @elseif ($p->type === 'texto')
                        <div class="pregunta">
                            <label for="p{{ $p->id }}">{{ $p->label }}@if ($p->required) <span style="color:#9B2C2C">*</span>@endif</label>
                            <textarea id="p{{ $p->id }}" name="{{ $campo }}" rows="3" maxlength="2000" @required($p->required)>{{ $viejo }}</textarea>
                        </div>
                    @else
                        <fieldset class="pregunta">
                            <legend>{{ $p->label }}@if ($p->required) <span style="color:#9B2C2C">*</span>@endif</legend>
                            @foreach ($p->opciones() as $o)
                                <label class="opcion"><input type="radio" name="{{ $campo }}" value="{{ $o }}" @required($p->required) @checked($viejo === $o)> {{ $o }}</label>
                            @endforeach
                        </fieldset>
                    @endif
                @endforeach

                <button type="submit" class="btn">Enviar respuestas</button>
            </form>
        @endif
    </section>
</main>
@endsection
