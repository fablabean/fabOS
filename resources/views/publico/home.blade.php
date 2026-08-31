@extends('layouts.publico')
@section('title', config('fabos.lab.name') . ' · ' . config('fabos.lab.tagline'))

@section('styles')
    .hero{
        background:var(--banner);color:var(--banner-ink);position:relative;overflow:hidden;
        padding:clamp(3rem,9vh,6rem) 1.4rem clamp(2.6rem,7vh,4.5rem);
    }
    .hero::before{
        content:"";position:absolute;inset:0;pointer-events:none;
        background-image:
            linear-gradient(to right, rgba(243,244,236,.05) 1px, transparent 1px),
            linear-gradient(to bottom, rgba(243,244,236,.05) 1px, transparent 1px);
        background-size:64px 64px;
        -webkit-mask-image:radial-gradient(120% 95% at 10% 0%, #000 20%, transparent 75%);
        mask-image:radial-gradient(120% 95% at 10% 0%, #000 20%, transparent 75%);
    }
    .hero::after{
        content:"";position:absolute;inset:0;pointer-events:none;
        background:radial-gradient(60% 100% at 88% 15%, rgba(92,201,184,.18), transparent 60%);
    }
    .hero .in{position:relative;z-index:1;max-width:70rem;margin:0 auto}
    .hero h1{color:var(--banner-ink);max-width:18ch}
    .hero h1 em{font-style:normal;color:var(--banner-accent)}
    .hero p{color:var(--banner-muted);font-size:1.1rem;max-width:46ch;margin:0 0 1.6rem}
    .hero .acciones{display:flex;gap:.7rem;flex-wrap:wrap}

    /* ---------- láminas rotatorias ---------- */
    /* Las ilustraciones van como fondo apilado y se cruzan por opacidad: así
       ninguna «salta» al entrar, y el texto nunca se mueve de sitio. */
    .laminas{position:absolute;inset:0;pointer-events:none}
    .lamina{
        position:absolute;inset:0;opacity:0;transition:opacity 1.1s ease;
        background-position:center;background-size:cover;background-repeat:no-repeat;
    }
    .lamina.activa{opacity:1}
    /* Vela sobre la ilustración para que el texto siempre se lea, venga la
       lámina que venga. */
    .hero .velo{
        position:absolute;inset:0;pointer-events:none;
        background:linear-gradient(100deg, rgba(23,26,21,.94) 0%, rgba(23,26,21,.86) 38%, rgba(23,26,21,.45) 100%);
    }
    .texto{position:relative;min-height:11.5rem}
    .diapo{
        position:absolute;inset:0;opacity:0;visibility:hidden;
        transform:translateY(.5rem);transition:opacity .5s ease, transform .5s ease;
    }
    .diapo.activa{opacity:1;visibility:visible;transform:none;position:relative}
    .puntos{display:flex;gap:.5rem;margin-top:1.4rem}
    .puntos button{
        margin:0;padding:0;width:2.2rem;height:4px;border:0;border-radius:2px;cursor:pointer;
        background:rgba(243,244,236,.25);transition:background .3s ease;
    }
    .puntos button[aria-current="true"]{background:var(--banner-accent)}
    @media (max-width:40rem){ .texto{min-height:15rem} }
    /* Sin animación, las láminas siguen rotando: lo que se quita es el cruce
       suave, no el contenido. */
    @media (prefers-reduced-motion:reduce){
        .lamina,.diapo{transition:none}
    }
    .cifras{
        display:flex;gap:2.6rem;flex-wrap:wrap;margin-top:2.6rem;
        padding-top:1.6rem;border-top:1px solid rgba(243,244,236,.16);
    }
    .cifra b{display:block;font-size:1.9rem;letter-spacing:-.03em;color:var(--banner-ink)}
    .cifra span{font-family:ui-monospace,Consolas,monospace;font-size:.66rem;
                letter-spacing:.14em;text-transform:uppercase;color:var(--banner-muted)}

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

@php $laminas = config('fabos.hero', []); @endphp

<div class="hero" id="hero">
    {{-- Las ilustraciones, apiladas. Van de fondo y no como <img> porque son
         decorativas: no aportan información que el texto no diga. --}}
    <div class="laminas">
        @foreach ($laminas as $i => $lamina)
            <div class="lamina {{ $i === 0 ? 'activa' : '' }}"
                 data-lamina="{{ $i }}"
                 style="background-image:url('{{ asset($lamina['imagen']) }}')"></div>
        @endforeach
    </div>
    <div class="velo"></div>

    <div class="in">
        <div class="texto">
            @foreach ($laminas as $i => $lamina)
                <div class="diapo {{ $i === 0 ? 'activa' : '' }}" data-diapo="{{ $i }}">
                    {{-- Sin rótulo propio, la lámina se presenta con la
                         identidad del laboratorio. --}}
                    <p class="rotulo" style="color:var(--banner-muted)">
                        {{ $lamina['rotulo']
                            ?? config('fabos.lab.institution') . ' · ' . config('fabos.lab.city') }}
                    </p>
                    {{-- Solo <em> viene de configuración, y la escribe quien
                         administra el sitio: no es contenido de usuario. --}}
                    <h1>{!! $lamina['titulo'] !!}</h1>
                    <p>{{ $lamina['texto'] }}</p>
                </div>
            @endforeach
        </div>

        @if (count($laminas) > 1)
            <div class="puntos" role="tablist" aria-label="Qué hace el laboratorio">
                @foreach ($laminas as $i => $lamina)
                    <button type="button" role="tab"
                            data-punto="{{ $i }}"
                            aria-current="{{ $i === 0 ? 'true' : 'false' }}"
                            aria-label="{{ $lamina['rotulo'] }}"></button>
                @endforeach
            </div>
        @endif

        <div class="acciones" style="margin-top:1.6rem">
            <a class="btn claro" href="{{ route('publico.equipos') }}">Ver los equipos</a>
            <a class="btn borde" href="{{ route('proyectos.solicitar') }}">Proponer un proyecto</a>
            @guest
                <a class="btn borde" href="{{ route('login') }}">Ingresar y reservar</a>
            @endguest
        </div>

        <div class="cifras">
            <div class="cifra"><b>{{ $cifras['equipos'] }}</b><span>equipos</span></div>
            <div class="cifra"><b>{{ $cifras['libres'] }}</b><span>libres ahora</span></div>
            <div class="cifra"><b>{{ $cifras['areas'] }}</b><span>áreas</span></div>
            {{-- Una cifra pequeña resta en vez de sumar: «1 persona habilitada»
                 comunica lo contrario de lo que se quiere. Aparece sola cuando
                 ya cuenta una historia; el umbral vive en config/fabos.php. --}}
            @if ($cifras['personas'] >= config('fabos.showcase.min_personas'))
                <div class="cifra"><b>{{ $cifras['personas'] }}</b><span>personas habilitadas</span></div>
            @endif
            <div class="cifra"><b>Fab</b><span>Academy acreditado</span></div>
        </div>
    </div>
</div>

<main>
    <section>
        <p class="rotulo">Qué hay</p>
        <h2 style="margin-bottom:1.2rem">Siete áreas de trabajo</h2>
        <div class="areas">
            @foreach ($areas as $area)
                {{-- Al area, no a un ancla: la pagina ya no pinta todas las
                     secciones de golpe, asi que el ancla no llevaba a ningun
                     sitio. --}}
                <a class="area" href="{{ route('publico.equipos', ['area' => $area->slug]) }}">
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
            <a href="{{ route('publico.equipos') }}">Ver el catálogo completo →</a>
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

{{-- Rotación del banner.
     Sin dependencias y con tres cuidados: se detiene al pasar el ratón o al
     enfocar con el teclado —para poder leer sin que se escape—, se detiene si
     la pestaña queda en segundo plano, y sigue rotando aunque el sistema pida
     menos animación: lo que se quita entonces es el cruce suave, no el
     contenido. --}}
<script>
(function () {
    const hero = document.getElementById('hero');
    if (! hero) return;

    const laminas = hero.querySelectorAll('[data-lamina]');
    const diapos  = hero.querySelectorAll('[data-diapo]');
    const puntos  = hero.querySelectorAll('[data-punto]');
    if (diapos.length < 2) return;

    const INTERVALO = 7000;
    let actual = 0;
    let reloj = null;

    function mostrar(i) {
        actual = (i + diapos.length) % diapos.length;

        laminas.forEach((l, n) => l.classList.toggle('activa', n === actual));
        diapos.forEach((d, n) => d.classList.toggle('activa', n === actual));
        puntos.forEach((p, n) => p.setAttribute('aria-current', n === actual ? 'true' : 'false'));
    }

    function arrancar() {
        detener();
        reloj = setInterval(() => mostrar(actual + 1), INTERVALO);
    }

    function detener() {
        if (reloj) { clearInterval(reloj); reloj = null; }
    }

    puntos.forEach((p, n) => p.addEventListener('click', () => { mostrar(n); arrancar(); }));

    hero.addEventListener('mouseenter', detener);
    hero.addEventListener('mouseleave', arrancar);
    hero.addEventListener('focusin', detener);
    hero.addEventListener('focusout', arrancar);

    document.addEventListener('visibilitychange', () => document.hidden ? detener() : arrancar());

    arrancar();
})();
</script>

@endsection
