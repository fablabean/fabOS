@extends('layouts.publico')
@section('title', $pregunta->title . ' · ' . config('fabos.lab.name'))

@section('content')
    <div class="envoltura">
        <p class="rotulo"><a href="{{ route('preguntas.index') }}">← Volver a preguntas</a></p>

        <h1>{{ $pregunta->title }}</h1>

        <p class="meta">
            {{ $pregunta->user?->name ?? 'Alguien' }} ·
            {{ $pregunta->created_at->diffForHumans() }}
            @if ($pregunta->area) · {{ $pregunta->area->name }} @endif
            @if ($pregunta->asset)
                · <a href="{{ route('publico.equipo', $pregunta->asset) }}">{{ $pregunta->asset->name }}</a>
            @endif
        </p>

        @if (session('status'))
            <p class="msg ok">{{ session('status') }}</p>
        @endif

        <div class="cuerpo">{!! nl2br(e($pregunta->body)) !!}</div>

        <h2>{{ $respuestas->isEmpty() ? 'Todavía sin responder' : 'Respuestas' }}</h2>

        @if ($respuestas->isEmpty())
            <p class="vacio">
                Nadie del laboratorio la ha respondido aún. Cuando lo hagan, aparecerá aquí.
            </p>
        @else
            @foreach ($respuestas as $r)
                <article class="respuesta">
                    <div class="cuerpo">{!! nl2br(e($r->body)) !!}</div>
                    <p class="meta">
                        {{ $r->user?->name ?? 'El laboratorio' }} ·
                        {{ $r->publicada_at?->diffForHumans() }}
                        @if ($r->vieneDeIa())
                            {{-- Quien lee tiene derecho a saber que hubo una
                                 máquina en el origen, aunque una persona lo haya
                                 revisado y corregido antes de publicarlo. --}}
                            <span class="marca ia">Borrador de IA, revisado por una persona</span>
                        @endif
                    </p>
                </article>
            @endforeach
        @endif

        {{-- Solo para quien puede publicar. --}}
        @if ($borradores->isNotEmpty() || (auth()->user()?->hasAnyRole([\App\Models\User::ROL_ADMINISTRADOR, \App\Models\User::ROL_SUPERADMIN]) ?? false))
            <div class="responder">
                <h2>Responder</h2>

                @foreach ($borradores as $b)
                    <p class="aviso">
                        Hay un borrador sin publicar{{ $b->vieneDeIa() ? ' sugerido por la IA' : '' }}.
                        Revísalo, corrígelo si hace falta, y publícalo.
                    </p>
                @endforeach

                <form method="POST" action="{{ route('preguntas.responder', $pregunta) }}">
                    @csrf
                    @if ($borradores->isNotEmpty())
                        <input type="hidden" name="borrador" value="{{ $borradores->first()->id }}">
                    @endif

                    <textarea name="body" rows="8" required
                              placeholder="Responde como se lo explicarías a alguien en el taller.">{{ old('body', $borradores->first()?->body) }}</textarea>

                    @error('body') <p class="msg error">{{ $message }}</p> @enderror

                    <button type="submit">Publicar respuesta</button>
                </form>
            </div>
        @endif

        @if ($parecidas->isNotEmpty())
            <h2>Preguntas parecidas</h2>
            <ul class="preguntas">
                @foreach ($parecidas as $p)
                    <li><a href="{{ route('preguntas.show', $p) }}">{{ $p->title }}</a></li>
                @endforeach
            </ul>
        @endif
    </div>

    <style>
        .envoltura { max-width: 46rem; margin: 0 auto; padding: 2rem 1.2rem 4rem; }
        .meta { font-size: .82rem; opacity: .65; }
        .cuerpo { margin: 1rem 0 2rem; line-height: 1.65; }
        .respuesta { border-left: 3px solid rgba(15,118,110,.35); padding-left: 1.1rem; margin-bottom: 2rem; }
        .respuesta .cuerpo { margin-bottom: .5rem; }
        .marca.ia { font-size: .7rem; text-transform: uppercase; letter-spacing: .04em;
                    background: rgba(99,102,241,.12); color: #4338ca;
                    padding: .1rem .4rem; border-radius: .3rem; margin-left: .3rem; }
        .responder { border-top: 1px solid var(--rule); margin-top: 2.5rem; padding-top: 1.5rem; }
        .responder textarea { width: 100%; padding: .7rem; border: 1px solid var(--rule);
                              border-radius: .5rem; font: inherit; }
        .aviso { border-left: 4px solid #4338ca; background: rgba(99,102,241,.08);
                 padding: .8rem 1rem; border-radius: .4rem; font-size: .9rem; }
        .preguntas { list-style: none; padding: 0; }
        .preguntas li { border-top: 1px solid var(--rule); padding: .7rem 0; }
        .vacio { opacity: .7; }
    </style>
@endsection
