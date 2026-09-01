@extends('layouts.publico')
@section('title', config('fabos.lab.name') . ' · ' . config('fabos.lab.tagline'))

@section('styles')
@include('publico.banner-estilos')

    .areas{display:grid;grid-template-columns:repeat(auto-fit,minmax(13rem,1fr));gap:.7rem}
    .area{
        background:var(--surface);border:1px solid var(--rule);border-radius:6px;
        padding:1rem;text-decoration:none;color:inherit;display:block;
    }
    .area:hover{border-color:var(--accent)}
    .area b{display:block;font-size:1rem;margin-bottom:.15rem}
    .area span{font-size:.85rem;color:var(--muted)}

    .equipos{display:grid;grid-template-columns:repeat(auto-fill,minmax(16rem,1fr));gap:1rem}
    .equipo{
        background:var(--surface);border:1px solid var(--rule);border-radius:6px;
        overflow:hidden;text-decoration:none;color:inherit;display:block;
    }
    .equipo:hover{border-color:var(--accent)}
    .equipo .foto{aspect-ratio:4/3;background:var(--ground);display:block;
                  width:100%;object-fit:cover}
    .equipo .sinfoto{
        aspect-ratio:4/3;display:flex;align-items:center;justify-content:center;
        background:var(--ground);color:var(--muted);
        font-family:ui-monospace,Consolas,monospace;font-size:.66rem;letter-spacing:.14em;
    }
    .equipo .txt{padding:.8rem .9rem}
    .equipo .txt b{display:block;font-size:.95rem;margin-bottom:.15rem}
    .equipo .txt span{font-size:.8rem;color:var(--muted)}

    .mapa{display:grid;grid-template-columns:repeat(auto-fill,minmax(15rem,1fr));gap:.7rem}
    .modulo{
        background:var(--surface);border:1px solid var(--rule);border-radius:6px;
        padding:.9rem 1rem;position:relative;
    }
    .modulo.listo{border-left:3px solid var(--accent)}
    .modulo.curso{border-left:3px solid #A45A17}
    .modulo.proximo{opacity:.72}
    .modulo b{display:block;font-size:.98rem;margin-bottom:.2rem}
    .modulo span{font-size:.86rem;color:var(--muted);display:block}
    .marca{
        display:inline-flex;align-items:center;gap:.35rem;
        font-family:ui-monospace,Consolas,monospace;font-size:.6rem;letter-spacing:.14em;
        text-transform:uppercase;margin-bottom:.45rem;
    }
    .marca.listo{color:var(--accent)}
    .marca.curso{color:#A45A17}
    .marca.proximo{color:var(--muted)}
    @media (prefers-color-scheme:dark){
        .modulo.curso{border-left-color:#DFA163}
        .marca.curso{color:#DFA163}
    }
    .avance{
        display:flex;align-items:center;gap:.8rem;margin:0 0 1.4rem;
        font-size:.88rem;color:var(--muted);flex-wrap:wrap;
    }
    .barra{flex:1;min-width:10rem;height:5px;background:var(--rule);border-radius:3px;overflow:hidden}
    .barra i{display:block;height:100%;background:var(--accent)}
@endsection

@section('content')

@include('publico.banner')

<main>
    <section>
        <p class="rotulo">Qué hay</p>
        <h2 style="margin-bottom:1.2rem">Siete áreas de trabajo</h2>
        <div class="areas">
            @foreach ($areas as $area)
                {{-- Al area, no a un ancla: la pagina ya no pinta todas las
                     secciones de golpe, asi que el ancla no llevaba a ningun
                     sitio. --}}
                <a class="area" href="{{ route('publico.reservas', ['area' => $area->slug]) }}">
                    <b>{{ $area->name }}</b>
                    <span>{{ $area->equipos_count }} {{ $area->equipos_count === 1 ? 'equipo' : 'equipos' }}</span>
                </a>
            @endforeach
        </div>
    </section>

    <section style="padding-top:0">
        <p class="rotulo">Algunos equipos</p>
        <h2 style="margin-bottom:1.2rem">Con lo que puedes trabajar</h2>
        <div class="equipos">
            @foreach ($destacados as $e)
                <a class="equipo" href="{{ route('publico.equipo', $e) }}">
                    @if ($e->photoUrl())
                        <img class="foto" src="{{ $e->photoUrl() }}" alt="{{ $e->name }}" loading="lazy">
                    @else
                        <div class="sinfoto">sin foto</div>
                    @endif
                    <div class="txt">
                        <b>{{ $e->name }}</b>
                        <span>{{ $e->area?->name }}</span>
                    </div>
                </a>
            @endforeach
        </div>
        <p style="margin-top:1.4rem">
            <a href="{{ route('publico.reservas') }}">Ver el catálogo completo →</a>
        </p>
    </section>

    <section style="padding-top:0">
        <p class="rotulo">En construcción</p>
        <h2 style="margin-bottom:.4rem">El sistema se está armando por partes</h2>
        <p class="lead" style="margin-bottom:1.4rem">
            Esto es lo que ya funciona y lo que viene. Se actualiza a medida que avanzamos.
        </p>

        @php
            $mapa = config('fabos.roadmap');
            $listos = collect($mapa)->where('estado', 'listo')->count();
            $total = count($mapa);
            $rotulos = ['listo' => 'Funcionando', 'curso' => 'En curso', 'proximo' => 'Próximo'];
        @endphp

        <div class="avance">
            <span><strong>{{ $listos }}</strong> de {{ $total }} módulos funcionando</span>
            <span class="barra"><i style="width:{{ round($listos / $total * 100) }}%"></i></span>
        </div>

        <div class="mapa">
            @foreach ($mapa as $m)
                <div class="modulo {{ $m['estado'] }}">
                    <span class="marca {{ $m['estado'] }}">{{ $rotulos[$m['estado']] }}</span>
                    <b>{{ $m['nombre'] }}</b>
                    <span>{{ $m['detalle'] }}</span>
                </div>
            @endforeach
        </div>
    </section>

    <section style="padding-top:0">
        <p class="rotulo">Cómo se usa</p>
        <h2 style="margin-bottom:1rem">Tres pasos</h2>
        <div class="areas">
            <div class="area">
                <b>1 · Ingresa</b>
                <span>Con tu correo institucional o escaneando tu carné digital. Sin contraseñas.</span>
            </div>
            <div class="area">
                <b>2 · Habilítate</b>
                <span>Cada equipo pide un certifab. Si no lo tienes, agendas una asesoría y sales habilitado.</span>
            </div>
            <div class="area">
                <b>3 · Reserva</b>
                <span>Eliges día y hora. Al llegar escaneas el QR de la máquina y empiezas.</span>
            </div>
        </div>
    </section>
</main>

@endsection
