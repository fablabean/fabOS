@extends('layouts.publico')
@section('title', 'Equipos · ' . config('fabos.lab.name'))

@section('styles')
    .equipos{display:grid;grid-template-columns:repeat(auto-fill,minmax(15rem,1fr));gap:1rem;margin-bottom:2.4rem}
    .equipo{background:var(--surface);border:1px solid var(--rule);border-radius:6px;
            overflow:hidden;text-decoration:none;color:inherit;display:block}
    .equipo:hover{border-color:var(--accent)}
    .equipo .foto{aspect-ratio:4/3;width:100%;object-fit:cover;display:block;background:var(--ground)}
    .equipo .sinfoto{aspect-ratio:4/3;display:flex;align-items:center;justify-content:center;
        background:var(--ground);color:var(--muted);
        font-family:ui-monospace,Consolas,monospace;font-size:.64rem;letter-spacing:.14em}
    .equipo .txt{padding:.75rem .85rem}
    .equipo .txt b{display:block;font-size:.92rem;margin-bottom:.1rem}
    .equipo .txt span{font-size:.78rem;color:var(--muted)}
    .estado{
        display:inline-flex;align-items:center;gap:.35rem;
        font-size:.68rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;
        padding:.15rem .45rem;border-radius:3px;margin-bottom:.3rem;
    }
    .estado::before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor}
    .estado.libre{color:#0D6E63;background:color-mix(in srgb,#0D6E63 12%,transparent)}
    .estado.ocupado{color:#A45A17;background:color-mix(in srgb,#A45A17 12%,transparent)}
    .estado.cerrado,
    .estado.accesorio{color:var(--muted);background:color-mix(in srgb,var(--muted) 12%,transparent)}
    .estado.no_operativo{color:#9B2C2C;background:color-mix(in srgb,#9B2C2C 12%,transparent)}
    @media (prefers-color-scheme:dark){
        .estado.libre{color:#5CC9B8}
        .estado.ocupado{color:#DFA163}
        .estado.no_operativo{color:#E08585}
    }

    /* Los filtros. Son enlaces, no botones de formulario: asi el filtro va en
       la direccion y se puede pegar en un chat, que es como se comparte un
       equipo con alguien. */
    .filtros{display:flex;flex-wrap:wrap;gap:.4rem;margin:0 0 1.6rem}
    .filtros a{
        display:inline-flex;align-items:baseline;gap:.35rem;
        padding:.4rem .8rem;border:1px solid var(--rule);border-radius:99px;
        background:var(--surface);color:var(--ink-soft);text-decoration:none;
        font-size:.85rem;line-height:1.2;
    }
    .filtros a:hover{border-color:var(--accent);color:var(--ink)}
    .filtros a.puesto{background:var(--accent);border-color:var(--accent);color:#fff;font-weight:600}
    .filtros a small{font-size:.72rem;opacity:.7;font-variant-numeric:tabular-nums}
    .filtros .separa{width:1px;background:var(--rule);margin:.2rem .35rem}
    .filtros a.libres.puesto{background:#0D6E63;border-color:#0D6E63}
    .vacio{color:var(--muted);padding:1.4rem 0 2.4rem}
@endsection

@section('content')
<main>
    <section style="padding-bottom:1rem">
        <p class="rotulo">Catálogo</p>
        <h1>Equipos del laboratorio</h1>
        <p class="lead">
            Todo lo que hay disponible, con su estado en este momento. Para reservar
            necesitas ingresar y tener el certifab del equipo.
        </p>
    </section>

    {{-- Las áreas son la primera pregunta de quien entra: noventa equipos en
         una lista se recorren con la rueda del ratón, y quien busca una
         cortadora láser no sabe si está más arriba o más abajo. --}}
    <nav class="filtros" aria-label="Filtrar equipos">
        <a href="{{ route('publico.equipos', $soloLibres ? ['libres' => 1] : []) }}"
           class="{{ $area === '' ? 'puesto' : '' }}">
            Todas <small>{{ $total }}</small>
        </a>

        @foreach ($areas as $a)
            <a href="{{ route('publico.equipos', array_filter([
                    'area'   => $a['slug'],
                    'libres' => $soloLibres ? 1 : null,
               ])) }}"
               class="{{ $area === $a['slug'] ? 'puesto' : '' }}">
                {{ $a['nombre'] }} <small>{{ $a['cuantos'] }}</small>
            </a>
        @endforeach

        <span class="separa" aria-hidden="true"></span>

        {{-- Lo que se puede usar ahora mismo: es lo que se pregunta cuando
             alguien ya está de pie en la puerta del laboratorio. --}}
        <a href="{{ route('publico.equipos', array_filter([
                'area'   => $area ?: null,
                'libres' => $soloLibres ? null : 1,
           ])) }}"
           class="libres {{ $soloLibres ? 'puesto' : '' }}">
            Libre ahora <small>{{ $libres }}</small>
        </a>
    </nav>

    {{-- `$nombreArea` y no `$area`: esa ya es el filtro puesto, y reusarla aquí
         la dejaría valiendo el último grupo pintado. --}}
    @foreach ($porArea as $nombreArea => $equipos)
        <section style="padding-top:1rem" id="{{ $equipos->first()->area?->slug }}">
            <p class="rotulo">{{ $nombreArea }} · {{ $equipos->count() }}</p>
            <div class="equipos">
                @foreach ($equipos as $e)
                    <a class="equipo" href="{{ route('publico.equipo', $e) }}">
                        @if ($e->photoUrl())
                            <img class="foto" src="{{ $e->photoUrl() }}" alt="{{ $e->name }}" loading="lazy">
                        @else
                            <div class="sinfoto">sin foto</div>
                        @endif
                        <div class="txt">
                            @php $est = $estados[$e->id] ?? null; @endphp
                            @if ($est)
                                <span class="estado {{ $est['estado'] }}">{{ $est['etiqueta'] }}</span>
                            @endif
                            <b>{{ $e->name }}</b>
                            <span>{{ $e->riskFamily?->name }}</span>
                        </div>
                    </a>
                @endforeach
            </div>
        </section>
    @endforeach

    @if ($porArea->isEmpty())
        <p class="vacio">
            @if ($soloLibres)
                Ahora mismo no hay nada libre aquí. Prueba en otra área, o mira el catálogo
                completo para reservar más tarde.
            @else
                No hay equipos publicados en esta área.
            @endif
        </p>
    @endif
</main>
@endsection
