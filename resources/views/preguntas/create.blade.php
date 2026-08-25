@extends('layouts.app')
@section('title', 'Preguntar · fabOS')

@section('content')
    <p class="rotulo"><a href="{{ route('preguntas.index') }}">← Volver a preguntas</a></p>
    <h1>Preguntar al laboratorio</h1>

    <p class="help">
        Alguien del equipo la responde y queda publicada, para que quien tenga la misma
        duda dentro de un mes la encuentre.
    </p>

    {{-- Antes de publicar, lo que ya está resuelto. Casi siempre la duda tiene
         respuesta, y verla aquí ahorra el trabajo de responderla otra vez. --}}
    @if ($parecidas->isNotEmpty())
        <div class="panel">
            <h2 style="margin-top:0">¿Es alguna de estas?</h2>
            <ul style="margin:0;padding-left:1.1rem">
                @foreach ($parecidas as $p)
                    <li style="margin:.4rem 0">
                        <a href="{{ route('preguntas.show', $p) }}">{{ $p->title }}</a>
                        @if ($p->respuestas_publicadas_count > 0)
                            <small style="opacity:.6">· respondida</small>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('preguntas.store') }}">
        @csrf

        <div class="panel">
            <label for="title">Tu pregunta</label>
            <input id="title" name="title" type="text" required minlength="10" maxlength="200"
                   value="{{ old('title', $titulo) }}"
                   placeholder="¿Qué resina sirve para hacer moldes de silicona?">
            @error('title') <p class="msg error">{{ $message }}</p> @enderror

            <label for="body">Cuéntanos más</label>
            <textarea id="body" name="body" rows="7" required minlength="20" maxlength="5000"
                      placeholder="Qué quieres hacer, qué has intentado, y qué te preocupa.">{{ old('body') }}</textarea>
            <p class="help" style="margin-top:.3rem">
                Cuanto más concreta, mejor la respuesta. Dinos para qué es y qué has probado.
            </p>
            @error('body') <p class="msg error">{{ $message }}</p> @enderror
        </div>

        <div class="panel">
            <h2 style="margin-top:0">¿Sobre qué es? <small style="opacity:.6;font-weight:400">(opcional)</small></h2>
            <p class="help" style="margin-top:0">
                Si no lo sabes, déjalo vacío: quien responda lo clasifica.
            </p>

            <div class="agenda">
                <div class="agenda-campo">
                    <label for="area_id">Área</label>
                    <select id="area_id" name="area_id">
                        <option value="">— cualquiera —</option>
                        @foreach ($areas as $a)
                            <option value="{{ $a->id }}" @selected(old('area_id') == $a->id)>{{ $a->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="agenda-campo">
                    <label for="asset_id">Equipo</label>
                    <select id="asset_id" name="asset_id">
                        <option value="">— ninguno en particular —</option>
                        @foreach ($equipos as $e)
                            <option value="{{ $e->id }}" @selected(old('asset_id') == $e->id)>{{ $e->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        <button type="submit">Publicar la pregunta</button>
    </form>

    <style>
        .agenda { display: grid; gap: .75rem 1rem; }
        .agenda-campo { display: flex; flex-direction: column; gap: .3rem; margin: 0; }
        .agenda-campo > label { margin: 0; }
        .agenda-campo > select { margin: 0; width: 100%; }
        @media (min-width: 720px) { .agenda { grid-template-columns: repeat(2, 1fr); } }
    </style>
@endsection
