@extends('layouts.publico')
@section('title', $equipo->name . ' · ' . config('fabos.lab.name'))
@section('description', $equipo->public_description ?: $equipo->name . ' en el ' . config('fabos.lab.name'))

@section('styles')
    .ficha{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,1fr);gap:2rem;align-items:start}
    @media (max-width:760px){ .ficha{grid-template-columns:minmax(0,1fr)} }
    .ficha img.foto{width:100%;border-radius:6px;border:1px solid var(--rule);display:block}
    .ficha .sinfoto{aspect-ratio:4/3;border:1px solid var(--rule);border-radius:6px;
        background:var(--surface);display:flex;align-items:center;justify-content:center;color:var(--muted);
        font-family:ui-monospace,Consolas,monospace;font-size:.7rem;letter-spacing:.14em}
    .video{aspect-ratio:16/9;width:100%;border:0;border-radius:6px;margin-top:1rem}
    .dato{display:flex;gap:.6rem;padding:.5rem 0;border-bottom:1px solid var(--rule);font-size:.9rem}
    .dato b{min-width:8rem;color:var(--muted);font-weight:500}
    .similares{display:grid;grid-template-columns:repeat(auto-fill,minmax(11rem,1fr));gap:.7rem;margin-top:1rem}
    .similares a{background:var(--surface);border:1px solid var(--rule);border-radius:5px;
        padding:.7rem .8rem;text-decoration:none;color:inherit;font-size:.88rem}
    .similares a:hover{border-color:var(--accent)}
    .estado{
        display:inline-flex;align-items:center;gap:.4rem;
        font-size:.72rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;
        padding:.25rem .6rem;border-radius:4px;margin-bottom:.5rem;
    }
    .estado::before{content:"";width:7px;height:7px;border-radius:50%;background:currentColor}
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
    <section>
        <p style="margin-bottom:1rem"><a href="{{ route('publico.equipos') }}">← Todos los equipos</a></p>

        <div class="ficha">
            <div>
                @if ($equipo->photoUrl())
                    <img class="foto" src="{{ $equipo->photoUrl() }}" alt="{{ $equipo->name }}">
                @else
                    <div class="sinfoto">sin foto todavía</div>
                @endif

                @if ($equipo->video_url)
                    {{-- Se muestra como enlace y no incrustado: no todos los
                         proveedores permiten incrustar, y un iframe roto se ve peor. --}}
                    <p style="margin-top:.9rem">
                        <a href="{{ $equipo->video_url }}" target="_blank" rel="noopener">Ver video del equipo →</a>
                    </p>
                @endif
            </div>

            <div>
                <p class="rotulo">{{ $equipo->area?->name }}</p>
                <span class="estado {{ $estado['estado'] }}">{{ $estado['etiqueta'] }}</span>
                <h1 style="margin-top:.2rem">{{ $equipo->name }}</h1>

                @if ($equipo->public_description)
                    <p class="lead">{{ $equipo->public_description }}</p>
                @endif

                <div style="margin-top:1.4rem">
                    @if ($equipo->riskFamily)
                        <div class="dato"><b>Familia</b><span>{{ $equipo->riskFamily->name }}</span></div>
                    @endif
                    @if ($equipo->riskFamily?->required_course_level)
                        <div class="dato">
                            <b>Nivel</b>
                            <span>Requiere curso {{ $equipo->riskFamily->required_course_level }}</span>
                        </div>
                    @endif
                    @if ($equipo->riskFamily?->requires_companion)
                        <div class="dato"><b>Acompañamiento</b><span>Se opera junto a un colaborador</span></div>
                    @endif
                    @if ($equipo->unattended_use)
                        <div class="dato"><b>Uso</b><span>El trabajo puede correr sin estar presente</span></div>
                    @endif
                    @if ($equipo->brand || $equipo->model)
                        <div class="dato"><b>Referencia</b><span>{{ trim($equipo->brand . ' ' . $equipo->model) }}</span></div>
                    @endif
                </div>

                <p style="margin-top:1.6rem;display:flex;gap:.6rem;flex-wrap:wrap">
                    @auth
                        <a class="btn" href="{{ route('reservas.show', $equipo) }}">Reservar este equipo</a>
                    @else
                        <a class="btn" href="{{ route('login') }}">Ingresar para reservar</a>
                    @endauth

                    {{-- La asesoria es la puerta para quien todavia no esta
                         habilitado, asi que tiene que verse ANTES de ingresar:
                         quien llega al catalogo sin cuenta es justo quien mas la
                         necesita. Sin sesion, el enlace pasa por el ingreso y
                         vuelve aqui. --}}
                    @if ($equipo->advisors_count > 0)
                        <a class="btn secundario" href="{{ route('asesoria.show', $equipo) }}">
                            Pedir asesoría
                        </a>
                    @endif
                </p>

                @if ($equipo->advisors_count > 0)
                    <p class="help" style="margin-top:.6rem">
                        ¿No tienes el certifab de este equipo? La asesoría no lo necesita:
                        alguien del laboratorio te acompaña y te enseña a usarlo.
                    </p>
                @endif
            </div>
        </div>

        @if ($similares->isNotEmpty())
            <p class="rotulo" style="margin-top:2.6rem">También en {{ $equipo->area?->name }}</p>
            <div class="similares">
                @foreach ($similares as $s)
                    <a href="{{ route('publico.equipo', $s) }}">{{ $s->name }}</a>
                @endforeach
            </div>
        @endif
    </section>
</main>
@endsection
