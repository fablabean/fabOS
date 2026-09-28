@extends('layouts.publico')
@section('title', ($esFabAcademy ? 'Fab Academy' : $curso->name) . ' · ' . config('fabos.lab.name'))
@section('description', $curso->summary ?: 'Preinscríbete a ' . $curso->name . ' en ' . config('fabos.lab.name') . '.')

@php
    $fab = config('fabos.formacion.fab_academy');
    $faltan = $cohorte?->faltanParaAbrir();
    $somos = $cohorte?->preinscritos() ?? 0;
@endphp

@section('styles')
    .portada{
        background:var(--banner);color:var(--banner-ink);
        padding:4rem 1.4rem 3.4rem;
    }
    .portada .in{max-width:70rem;margin:0 auto}
    .portada .rotulo{color:var(--banner-muted)}
    .portada h1{font-size:clamp(2.2rem,6vw,4rem);margin-bottom:1rem}
    .portada h1 em{font-style:normal;color:var(--banner-accent)}
    .portada p.lead{color:var(--banner-muted);max-width:52ch;font-size:1.12rem}
    .distintivo{
        display:inline-block;border:1px solid var(--banner-accent);color:var(--banner-accent);
        border-radius:999px;padding:.3rem .9rem;font-size:.8rem;font-weight:600;
        letter-spacing:.04em;margin-bottom:1.4rem;text-decoration:none;
    }
    .datos{display:flex;flex-wrap:wrap;gap:2.2rem;margin:2rem 0 0}
    .datos div{min-width:9rem}
    .datos dt{font-family:ui-monospace,Consolas,monospace;font-size:.64rem;letter-spacing:.16em;
              text-transform:uppercase;color:var(--banner-muted)}
    .datos dd{margin:.2rem 0 0;font-size:1.05rem;font-weight:600}

    .dos{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,1fr);gap:3rem;align-items:start}
    @media (max-width:860px){.dos{grid-template-columns:1fr;gap:1.6rem}}

    .semanas{list-style:none;padding:0;margin:1rem 0 0;display:grid;
             grid-template-columns:repeat(auto-fill,minmax(13rem,1fr));gap:.5rem}
    .semanas li{background:var(--surface);border:1px solid var(--rule);border-radius:6px;
                padding:.55rem .8rem;font-size:.9rem}

    .como{counter-reset:paso;list-style:none;padding:0;margin:1rem 0 0}
    .como li{counter-increment:paso;position:relative;padding:0 0 1.1rem 2.6rem}
    .como li::before{
        content:counter(paso);position:absolute;left:0;top:.1rem;width:1.7rem;height:1.7rem;
        border-radius:50%;background:var(--accent);color:var(--surface);
        display:grid;place-items:center;font-size:.8rem;font-weight:700;
    }
    .como b{display:block}
    .como span{color:var(--muted);font-size:.92rem}

@endsection

@section('content')
<header class="portada">
    <div class="in">
        @if ($esFabAcademy && filled($fab['distintivo'] ?? null))
            {{-- Enlazado a la lista de la propia Fab Academy: decir «somos el
                 único» sin enseñar dónde se comprueba es solo decirlo. --}}
            <a class="distintivo" href="{{ $fab['nodos_url'] }}" target="_blank" rel="noopener">
                {{ $fab['distintivo'] }} ↗
            </a>
        @endif

        <p class="rotulo">{{ config('fabos.lab.name') }} · nivel {{ $curso->level }}</p>

        @if ($esFabAcademy)
            <h1>Fab Academy: aprende a fabricar <em>(casi) cualquier cosa</em></h1>
        @else
            <h1>{{ $curso->name }}</h1>
        @endif

        @if ($curso->summary)
            <p class="lead">{{ $curso->summary }}</p>
        @endif

        <dl class="datos">
            @if ($cohorte?->starts_on)
                <div><dt>Empieza</dt><dd>{{ ucfirst($cohorte->starts_on->locale('es')->isoFormat('MMMM [de] YYYY')) }}</dd></div>
            @endif
            @if ($curso->hours)
                <div><dt>Dedicación</dt><dd>{{ $curso->hours }} horas</dd></div>
            @endif
            <div><dt>Dónde</dt><dd>{{ $cohorte?->space?->name ?? config('fabos.lab.name') }}, {{ config('fabos.lab.city') }}</dd></div>
            @if ($cohorte?->price_note)
                <div><dt>Inversión</dt><dd>{{ $cohorte->price_note }}</dd></div>
            @endif
        </dl>

        {{-- En el teléfono el formulario queda debajo de toda la explicación:
             quien ya venía convencido no debería tener que buscarlo. --}}
        @if ($cohorte?->admitePreinscripciones() && ! $abierta)
            <p style="margin:2rem 0 0">
                <a class="btn claro" href="#preinscripcion">Preinscribirme</a>
                @if ($faltan)
                    <span style="margin-left:.8rem;color:var(--banner-muted);font-size:.92rem">
                        {{ $faltan === 1 ? 'Falta una persona' : 'Faltan ' . $faltan }} para abrir la cohorte
                    </span>
                @endif
            </p>
        @endif
    </div>
</header>

<main>
    <section class="dos">
        {{-- ------------------------------------------------ por qué --}}
        <div>
            @if ($esFabAcademy)
                <p class="rotulo">Qué es</p>
                <h2>El curso del MIT, hecho en tu ciudad</h2>
                <p>
                    Fab Academy es el programa de la Fab Foundation que dirige Neil Gershenfeld,
                    del Center for Bits and Atoms del MIT. Nace de su curso
                    <em>How To Make (almost) Anything</em> y se dicta a la vez en laboratorios de
                    todo el mundo: cada semana hay una clase global por videoconferencia, y el
                    resto de la semana se trabaja <strong>aquí, en el laboratorio</strong>, con las
                    máquinas y con los instructores locales del programa.
                </p>
                <p>
                    Cada semana se aprende una técnica nueva y se entrega algo hecho con ella.
                    Todo se documenta en un sitio propio, que al final es un portafolio que
                    cualquiera puede revisar. El programa cierra con un proyecto final que las
                    integra, y el diploma lo otorga Fab Academy tras una evaluación global: vale lo
                    mismo aquí que en Barcelona, Boston o Kamakura.
                </p>

                <h2 style="margin-top:2.2rem">Lo que vas a saber hacer</h2>
                <ul class="semanas">
                    <li>Diseño asistido por computador</li>
                    <li>Corte láser y de vinilo</li>
                    <li>Producción de circuitos</li>
                    <li>Impresión y escaneo 3D</li>
                    <li>Diseño de electrónica</li>
                    <li>Fresado CNC a gran formato</li>
                    <li>Programación embebida</li>
                    <li>Moldes y vaciado</li>
                    <li>Sensores y actuadores</li>
                    <li>Redes y comunicaciones</li>
                    <li>Interfaces y aplicaciones</li>
                    <li>Diseño de máquinas</li>
                    <li>Propiedad intelectual y negocio</li>
                    <li>Proyecto final</li>
                </ul>
            @endif

            @if ($curso->description)
                <div style="margin-top:2rem">{!! nl2br(e($curso->description)) !!}</div>
            @endif

            @if ($curso->requirements)
                <p style="margin-top:1.6rem"><strong>Para entrar:</strong> {{ $curso->requirements }}</p>
            @endif

            @if ($curso->riskFamilies->isNotEmpty())
                <p style="color:var(--muted);font-size:.92rem">
                    <strong>En el laboratorio te habilita:</strong>
                    {{ $curso->riskFamilies->pluck('name')->implode(', ') }}.
                </p>
            @endif

            <h2 style="margin-top:2.2rem">Por qué preinscribirse</h2>
            {{-- Decir cómo funciona antes de pedir datos: «preinscripción» suena
                 a compromiso de pago, y quien cree eso no llena el formulario. --}}
            <ol class="como">
                <li>
                    <b>Te preinscribes.</b>
                    <span>No pagas nada ni te comprometes: nos dices que te interesa y cómo lo financiarías.</span>
                </li>
                <li>
                    <b>Contamos cuántos somos.</b>
                    <span>
                        La cohorte solo se abre si se junta gente suficiente
                        @if ($cohorte?->minimum_to_open) —hacen falta {{ $cohorte->minimum_to_open }}—@endif.
                        Por eso cada preinscripción cuenta, y por eso vale la pena compartir esta página.
                    </span>
                </li>
                <li>
                    <b>Te escribimos.</b>
                    <span>Si la cohorte abre, eres de los primeros en saberlo y en asegurar cupo. Si no, también te lo decimos.</span>
                </li>
            </ol>
        </div>

        {{-- ------------------------------------------- preinscripción --}}
        <div id="preinscripcion">
            @include('formacion._preinscripcion-ficha')
        </div>
    </section>
</main>
@endsection
