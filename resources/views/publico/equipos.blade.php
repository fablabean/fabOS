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

    @foreach ($porArea as $area => $equipos)
        <section style="padding-top:1rem" id="{{ $equipos->first()->area?->slug }}">
            <p class="rotulo">{{ $area }} · {{ $equipos->count() }}</p>
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
</main>
@endsection
