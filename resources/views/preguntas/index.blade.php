@extends('layouts.publico')
@section('title', 'Preguntas · ' . config('fabos.lab.name'))

@section('content')
    <div class="envoltura">
        <p class="rotulo">Preguntas</p>
        <h1>Lo que se pregunta en el laboratorio</h1>

        <p class="lead">
            Dudas resueltas por el equipo del laboratorio. Si la tuya no está,
            @auth
                <a href="{{ route('preguntas.create') }}">pregúntala</a>.
            @else
                <a href="{{ route('login') }}">ingresa</a> y pregúntala.
            @endauth
        </p>

        <form method="GET" action="{{ route('preguntas.index') }}" class="buscador">
            <input type="search" name="q" value="{{ $busqueda }}"
                   placeholder="¿Qué resina sirve para moldes?" aria-label="Buscar">
            <button type="submit">Buscar</button>
        </form>

        <p class="filtros">
            <a href="{{ route('preguntas.index') }}" @class(['activo' => ! request('estado') && ! request('area')])>Todas</a>
            <a href="{{ route('preguntas.index', ['estado' => 'sin_responder']) }}"
               @class(['activo' => request('estado') === 'sin_responder'])>Sin responder</a>
            @foreach ($areas as $a)
                <a href="{{ route('preguntas.index', ['area' => $a->id]) }}"
                   @class(['activo' => request('area') == $a->id])>{{ $a->name }}</a>
            @endforeach
        </p>

        @if ($preguntas->isEmpty())
            <div class="vacio">
                @if ($busqueda !== '')
                    <p>Nadie ha preguntado eso todavía.</p>
                    @auth
                        <p><a href="{{ route('preguntas.create', ['titulo' => $busqueda]) }}">Sé la primera persona en preguntarlo →</a></p>
                    @endauth
                @else
                    <p>Todavía no hay preguntas.</p>
                @endif
            </div>
        @else
            <ul class="preguntas">
                @foreach ($preguntas as $p)
                    <li>
                        <a href="{{ route('preguntas.show', $p) }}">
                            <span class="titulo">{{ $p->title }}</span>
                            <span class="meta">
                                @if ($p->respuestas_publicadas_count > 0)
                                    <span class="marca resuelta">
                                        {{ $p->respuestas_publicadas_count }}
                                        {{ $p->respuestas_publicadas_count === 1 ? 'respuesta' : 'respuestas' }}
                                    </span>
                                @else
                                    <span class="marca abierta">Sin responder</span>
                                @endif
                                @if ($p->area) · {{ $p->area->name }} @endif
                                @if ($p->asset) · {{ $p->asset->name }} @endif
                                · {{ $p->created_at->diffForHumans() }}
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>

            {{ $preguntas->links() }}
        @endif
    </div>

    <style>
        .envoltura { max-width: 52rem; margin: 0 auto; padding: 2rem 1.2rem 4rem; }
        .buscador { display: flex; gap: .5rem; margin: 1.4rem 0 1rem; }
        .buscador input { flex: 1; padding: .6rem .8rem; border: 1px solid var(--rule); border-radius: .5rem; }
        .filtros { display: flex; flex-wrap: wrap; gap: .4rem .9rem; font-size: .85rem; margin-bottom: 1.6rem; }
        .filtros a { opacity: .7; }
        .filtros a.activo { opacity: 1; font-weight: 700; text-decoration: underline; }
        .preguntas { list-style: none; padding: 0; margin: 0; }
        .preguntas li { border-top: 1px solid var(--rule); }
        .preguntas a { display: block; padding: 1rem 0; }
        .preguntas .titulo { display: block; font-weight: 600; margin-bottom: .25rem; }
        .preguntas .meta { font-size: .82rem; opacity: .65; }
        .marca { font-size: .72rem; text-transform: uppercase; letter-spacing: .04em;
                 padding: .1rem .4rem; border-radius: .3rem; }
        .marca.resuelta { background: rgba(16,185,129,.15); color: #047857; }
        .marca.abierta { background: rgba(180,83,9,.12); color: #b45309; }
        .vacio { padding: 3rem 0; text-align: center; opacity: .7; }
    </style>
@endsection
