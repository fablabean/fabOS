@extends('layouts.publico')
@section('title', 'Fab Academy · ' . config('fabos.lab.name'))
@section('description', $curso->summary ?: 'Fab Academy en ' . config('fabos.lab.name') . ': aprende a fabricar (casi) cualquier cosa.')

@use('App\Support\PaginaFabAcademy', 'P')

@if (filled($pagina['video_portada']['imagen'] ?? null))
    @section('imagen', P::url($pagina['video_portada']['imagen']))
@endif
@push('datos-estructurados')
    {!! \App\Services\Buscadores\DatosEstructurados::etiqueta(
        app(\App\Services\Buscadores\DatosEstructurados::class)->fabAcademy($curso, $cohorte),
        app(\App\Services\Buscadores\DatosEstructurados::class)->preguntasFrecuentes($pagina['faqs'] ?? []) ?? [],
    ) !!}
@endpush

@php
    $fab = config('fabos.formacion.fab_academy');
    $faltan = $cohorte?->faltanParaAbrir();
    $somos = $cohorte?->preinscritos() ?? 0;
    $lab = config('fabos.lab.name');
    $ciudad = config('fabos.lab.city');

    // El video de la portada: el archivo manda sobre el enlace.
    $vp = $pagina['video_portada'];
    $videoPortada = P::url($vp['archivo'] ?? null);
    $embedPortada = $videoPortada ? null : P::embed($vp['url'] ?? null);
    $imagenPortada = P::url($vp['imagen'] ?? null);

    // De fondo, el video (o la imagen) llena la portada detrás del título.
    $deFondo = ($vp['estilo'] ?? 'fondo') === 'fondo' && ($videoPortada || $embedPortada || $imagenPortada);
    $embedDeFondo = $deFondo && ! $videoPortada ? P::embedDeFondo($vp['url'] ?? null) : null;
    // Para verlo con sonido: el archivo tiene su botón; un enlace se abre donde vive.
    $verConSonido = $deFondo && ! $videoPortada && filled($vp['url'] ?? null) ? $vp['url'] : null;

    $vl = $pagina['laboratorio'];
    $videoLab = P::url($vl['video_archivo'] ?? null);
    $embedLab = $videoLab ? null : P::embed($vl['video_url'] ?? null);

    $proyectos = collect($pagina['proyectos'])->filter(fn ($p) => filled($p['nombre'] ?? null));
    $destacado = $proyectos->firstWhere('destacado', true) ?? $proyectos->first();
    $resto = $proyectos->reject(fn ($p) => $p === $destacado);

    $equipo = collect($pagina['equipo'])->filter(fn ($p) => filled($p['nombre'] ?? null));
    $tecnologias = collect($vl['tecnologias'] ?? [])->filter(fn ($t) => filled($t['nombre'] ?? null));

    // Lo que el laboratorio habilita: lo escrito en el panel más las familias
    // de riesgo que el curso ya tiene asociadas, sin repetir.
    $habilita = collect(P::lineas($pagina['certificacion']))
        ->merge($curso->riskFamilies->pluck('name'))
        ->unique(fn ($n) => mb_strtolower($n))->values();

    $precio = $cohorte?->price_note;
    $abreEl = $cohorte?->starts_on ? ucfirst($cohorte->starts_on->locale('es')->isoFormat('MMMM [de] YYYY')) : null;

    $tonos = ['#0D6E63', '#B45309', '#1D4ED8', '#7C3AED', '#BE123C', '#0F766E'];
@endphp

@section('styles')
    .fa{--fa-radio:14px}
    .fa .rotulo{margin-bottom:.4rem}
    .fa h2{font-size:clamp(1.45rem,3vw,1.9rem);margin-bottom:.6rem}
    .fa section{padding:2.6rem 0;border-bottom:1px solid var(--rule)}
    .fa section:last-child{border-bottom:0}
    .fa .intro{color:var(--muted);max-width:60ch;margin:0 0 1.4rem}
    .tarjeta{background:var(--surface);border:1px solid var(--rule);border-radius:var(--fa-radio);overflow:hidden}
    .foto{display:block;width:100%;height:100%;object-fit:cover;background:color-mix(in srgb,var(--accent) 12%,var(--surface))}
    .sinfoto{display:grid;place-items:center;color:var(--accent);font-weight:800;font-size:2rem;
             background:linear-gradient(135deg,color-mix(in srgb,var(--accent) 18%,var(--surface)),var(--surface))}
    .chips{display:flex;flex-wrap:wrap;gap:.4rem;margin:.6rem 0 0;padding:0;list-style:none}
    .chips li{font-size:.78rem;padding:.2rem .6rem;border-radius:999px;border:1px solid var(--rule);background:var(--ground)}
    .fila-botones{display:flex;flex-wrap:wrap;gap:.6rem;align-items:center}

    /* ---------- índice ---------- */
    .indice{display:flex;gap:.4rem;flex-wrap:wrap;padding:1rem 0 0}
    .indice a{font-size:.85rem;text-decoration:none;color:var(--ink-soft);padding:.3rem .8rem;border-radius:999px;border:1px solid var(--rule)}
    .indice a:hover{border-color:var(--accent);color:var(--accent)}

    /* ---------- portada ---------- */
    .fa-portada{display:grid;grid-template-columns:minmax(0,1.05fr) minmax(0,1fr);gap:2.4rem;align-items:center;padding:2.4rem 0 2.6rem}
    .fa-portada.sola{grid-template-columns:1fr}
    .fa-portada h1{font-size:clamp(2.1rem,5.4vw,3.6rem);line-height:1.05;letter-spacing:-.035em;margin:.2rem 0 1rem}
    .fa-portada h1 em{font-style:normal;color:var(--accent)}
    .fa-portada .lead{font-size:1.08rem;color:var(--ink-soft);max-width:48ch}
    .distintivo{display:inline-block;border:1px solid var(--accent);color:var(--accent);border-radius:999px;
                padding:.25rem .85rem;font-size:.78rem;font-weight:600;letter-spacing:.03em;text-decoration:none}
    .datos{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.6rem;margin:1.6rem 0 0}
    .datos div{background:var(--surface);border:1px solid var(--rule);border-radius:10px;padding:.6rem .75rem}
    .datos dt{font-family:ui-monospace,Consolas,monospace;font-size:.6rem;letter-spacing:.14em;text-transform:uppercase;color:var(--muted)}
    .datos dd{margin:.15rem 0 0;font-weight:700;font-size:.95rem;line-height:1.3}
    .marco{position:relative;border-radius:18px;overflow:hidden;aspect-ratio:16/10;background:#111;box-shadow:0 18px 40px -22px rgba(0,0,0,.45)}
    .marco video,.marco iframe,.marco img{position:absolute;inset:0;width:100%;height:100%;border:0;object-fit:cover}
    .marco .leyenda{position:absolute;left:0;right:0;bottom:0;padding:1.4rem 1rem .8rem;color:#fff;font-weight:600;font-size:.92rem;
                    background:linear-gradient(transparent,rgba(0,0,0,.7));pointer-events:none}
    @media (max-width:860px){
        .fa-portada{grid-template-columns:1fr;gap:1.6rem}
        .datos{grid-template-columns:repeat(2,minmax(0,1fr))}
    }

    /* ---------- portada con video o imagen de fondo ---------- */
    html,body{overflow-x:clip}
    .fa-portada.fondo{position:relative;display:block;margin:1rem calc(50% - 50vw) 0;padding:0;min-height:min(86vh,46rem);
                      color:#fff;overflow:hidden;background:#111;isolation:isolate}
    .fa-portada.fondo .capa{position:absolute;inset:0;z-index:-2;container-type:size;overflow:hidden}
    .fa-portada.fondo .capa video,.fa-portada.fondo .capa img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
    /* Un iframe no sabe «cubrir»: se agranda hasta tapar el recuadro, en 16:9. */
    .fa-portada.fondo .capa iframe{position:absolute;top:50%;left:50%;border:0;pointer-events:none;
                      width:max(100cqw,177.78cqh);height:max(100cqh,56.25cqw);
                      /* Un poco más grande que el recuadro: el título y el logo que YouTube
                         pinta en los bordes quedan fuera de cuadro. */
                      transform:translate(-50%,-50%) scale(1.3)}
    .fa-portada.fondo::before{content:"";position:absolute;inset:0;z-index:-1;
                      background:linear-gradient(90deg,rgba(8,12,10,.86) 0%,rgba(8,12,10,.62) 45%,rgba(8,12,10,.25) 100%),
                                 linear-gradient(0deg,rgba(8,12,10,.55),transparent 40%)}
    .fa-portada.fondo .in{max-width:70rem;margin:0 auto;padding:4.5rem 1.4rem 3.4rem;min-height:inherit;
                      display:flex;flex-direction:column;justify-content:center;box-sizing:border-box}
    .fa-portada.fondo .texto{max-width:40rem}
    .fa-portada.fondo h1{color:#fff}
    .fa-portada.fondo h1 em{color:#5CC9B8}
    .fa-portada.fondo .lead{color:rgba(255,255,255,.86)}
    .fa-portada.fondo .distintivo{border-color:#5CC9B8;color:#5CC9B8}
    .fa-portada.fondo .btn.secundario{color:#fff;border-color:rgba(255,255,255,.55);background:transparent}
    .fa-portada.fondo .btn.secundario:hover{background:rgba(255,255,255,.1)}
    .fa-portada.fondo .datos{max-width:40rem}
    .fa-portada.fondo .datos div{background:rgba(255,255,255,.1);border-color:rgba(255,255,255,.22);backdrop-filter:blur(6px)}
    .fa-portada.fondo .datos dt{color:rgba(255,255,255,.7)}
    .sonido{display:inline-flex;align-items:center;gap:.45rem;background:rgba(0,0,0,.45);color:#fff;border:1px solid rgba(255,255,255,.35);
            border-radius:999px;padding:.45rem .95rem;font:inherit;font-size:.88rem;cursor:pointer;text-decoration:none;backdrop-filter:blur(6px)}
    .sonido:hover{background:rgba(0,0,0,.6)}
    .fa-portada.fondo .sonido{position:absolute;right:1.2rem;bottom:1.2rem}
    @media (max-width:860px){
        .fa-portada.fondo{min-height:auto}
        .fa-portada.fondo::before{background:linear-gradient(0deg,rgba(8,12,10,.9) 10%,rgba(8,12,10,.55))}
        .fa-portada.fondo .in{padding:3.2rem 1.4rem 4.6rem}
    }

    /* ---------- qué es ---------- */
    .pasos{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:1rem;counter-reset:paso}
    .paso .img{aspect-ratio:4/3}
    .paso .cuerpo{padding:.9rem 1rem 1.1rem}
    .paso h3{margin:0 0 .25rem;font-size:1.05rem;display:flex;gap:.5rem;align-items:center}
    .paso h3::before{counter-increment:paso;content:"0" counter(paso);font-family:ui-monospace,Consolas,monospace;
                     font-size:.72rem;color:var(--accent);border:1px solid var(--accent);border-radius:6px;padding:.05rem .3rem}
    .paso p{margin:0;font-size:.9rem;color:var(--muted)}
    @media (max-width:860px){.pasos{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media (max-width:480px){.pasos{grid-template-columns:1fr}}

    /* ---------- semana ---------- */
    .linea{list-style:none;padding:0;margin:0;display:grid;grid-template-columns:repeat(var(--n),minmax(0,1fr));gap:.8rem;position:relative}
    .linea::before{content:"";position:absolute;left:1.1rem;right:1.1rem;top:1.1rem;height:2px;background:var(--rule)}
    .linea li{position:relative}
    .linea .punto{width:2.2rem;height:2.2rem;border-radius:50%;background:var(--accent);color:var(--surface);
                  display:grid;place-items:center;font-weight:700;font-size:.85rem;position:relative}
    .linea b{display:block;margin-top:.6rem;line-height:1.25}
    .linea span{font-size:.86rem;color:var(--muted)}
    @media (max-width:760px){
        .linea{grid-template-columns:1fr;gap:1rem}
        .linea::before{left:1.1rem;right:auto;top:0;bottom:0;width:2px;height:auto}
        .linea li{display:grid;grid-template-columns:2.2rem 1fr;column-gap:.9rem}
        .linea b{margin-top:.2rem}
        .linea span{grid-column:2}
    }

    /* ---------- habilidades ---------- */
    .categorias{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:.9rem;align-items:start}
    .categoria{border-top:4px solid var(--tono)}
    .categoria .img{aspect-ratio:16/10}
    .categoria .sinfoto{color:var(--tono);background:color-mix(in srgb,var(--tono) 12%,var(--surface));font-size:1.1rem;aspect-ratio:16/10}
    .categoria .cuerpo{padding:.8rem .9rem 1rem}
    .categoria h3{margin:0;font-size:1rem}
    .categoria details summary{cursor:pointer;color:var(--tono);font-size:.85rem;font-weight:600;margin-top:.4rem;list-style:none}
    .categoria details summary::-webkit-details-marker{display:none}
    .categoria details summary::after{content:" +"}
    .categoria details[open] summary::after{content:" −"}
    .categoria ul{margin:.5rem 0 0;padding-left:1.1rem;font-size:.86rem;color:var(--ink-soft)}
    .categoria .primeros{font-size:.84rem;color:var(--muted);margin:.35rem 0 0}
    @media (max-width:1000px){.categorias{grid-template-columns:repeat(3,minmax(0,1fr))}}
    @media (max-width:640px){.categorias{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media (max-width:420px){.categorias{grid-template-columns:1fr}}

    /* ---------- dos columnas genéricas ---------- */
    .par{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.1fr);gap:2.2rem;align-items:center}
    .par.al-reves{grid-template-columns:minmax(0,1.1fr) minmax(0,1fr)}
    .par .img{border-radius:var(--fa-radio);overflow:hidden;aspect-ratio:16/10;border:1px solid var(--rule)}
    @media (max-width:860px){.par,.par.al-reves{grid-template-columns:1fr;gap:1.2rem}}

    /* ---------- proyectos ---------- */
    .destacado{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(0,1fr)}
    .destacado .img{min-height:18rem}
    .destacado .cuerpo{padding:1.4rem 1.5rem}
    .destacado h3{font-size:1.4rem;margin:.2rem 0 .4rem}
    @media (max-width:760px){.destacado{grid-template-columns:1fr}.destacado .img{min-height:13rem}}
    .carrusel{display:grid;grid-auto-flow:column;grid-auto-columns:minmax(15rem,1fr);gap:.9rem;overflow-x:auto;
              scroll-snap-type:x mandatory;padding:.2rem 0 .8rem;margin-top:1rem}
    .carrusel .tarjeta{scroll-snap-align:start}
    .carrusel .img{aspect-ratio:4/3}
    .carrusel .cuerpo{padding:.8rem .9rem 1rem}
    .carrusel h3{font-size:1rem;margin:0}
    .meta{font-size:.82rem;color:var(--muted);margin:.15rem 0 0}

    /* ---------- laboratorio ---------- */
    .panoramica{border-radius:var(--fa-radio);overflow:hidden;aspect-ratio:21/8;border:1px solid var(--rule)}
    @media (max-width:640px){.panoramica{aspect-ratio:16/9}}
    .tecnologias{display:grid;grid-template-columns:repeat(auto-fill,minmax(8.5rem,1fr));gap:.7rem;margin-top:.9rem}
    .tecnologias .img{aspect-ratio:1}
    .tecnologias p{margin:0;padding:.5rem .6rem;font-size:.85rem;font-weight:600}
    .video-lab{margin-top:1.4rem}

    /* ---------- red global ---------- */
    .cadena{list-style:none;padding:0;margin:0;display:grid;gap:.5rem}
    .cadena li{background:var(--surface);border:1px solid var(--rule);border-radius:10px;padding:.7rem .9rem}
    .cadena li + li{position:relative}
    .cadena li + li::before{content:"↓";position:absolute;top:-.95rem;left:1.2rem;color:var(--accent);font-weight:700}
    .cadena b{display:block}
    .cadena span{font-size:.85rem;color:var(--muted)}
    .cadena li.aqui{border-color:var(--accent);background:color-mix(in srgb,var(--accent) 10%,var(--surface))}
    .tres{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1.4rem;align-items:start}
    @media (max-width:900px){.tres{grid-template-columns:1fr}}

    /* ---------- del interés al diploma ---------- */
    .cuatro{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:1rem;align-items:stretch}
    .cuatro .tarjeta{padding:1.1rem 1.1rem 1.2rem}
    .cuatro h3{font-size:1.02rem;margin:0 0 .6rem}
    @media (max-width:1000px){.cuatro{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media (max-width:560px){.cuatro{grid-template-columns:1fr}}
    .flujo{counter-reset:f;list-style:none;padding:0;margin:0}
    .flujo li{counter-increment:f;display:flex;gap:.6rem;align-items:center;font-size:.9rem;padding:.25rem 0}
    .flujo li::before{content:counter(f);flex:none;width:1.4rem;height:1.4rem;border-radius:50%;
                      background:var(--accent);color:var(--surface);display:grid;place-items:center;font-size:.72rem;font-weight:700}
    .checks{list-style:none;padding:0;margin:0;font-size:.9rem}
    .checks li{padding:.2rem 0 .2rem 1.5rem;position:relative}
    .checks li::before{content:"✓";position:absolute;left:0;color:var(--accent);font-weight:800}
    .checks.no li::before{content:"✕";color:#9B2C2C}
    .insignias{display:flex;flex-wrap:wrap;gap:.4rem;list-style:none;padding:0;margin:0}
    .insignias li{font-size:.8rem;font-weight:600;padding:.3rem .65rem;border-radius:8px;
                  background:color-mix(in srgb,var(--accent) 12%,var(--surface));color:var(--accent)}
    .insignias li::after{content:" ✓"}
    .nota{font-size:.82rem;color:var(--muted);margin:.7rem 0 0}
    .grad-img{aspect-ratio:16/9;border-radius:10px;overflow:hidden;margin-bottom:.7rem}

    /* ---------- equipo ---------- */
    .equipo{display:grid;grid-template-columns:repeat(auto-fill,minmax(14rem,1fr));gap:1rem}
    .persona{display:grid;grid-template-columns:4.2rem 1fr;gap:.9rem;padding:1rem;align-items:start}
    .persona .img{width:4.2rem;height:4.2rem;border-radius:50%;overflow:hidden}
    .persona .sinfoto{font-size:1.3rem}
    .persona h3{margin:0;font-size:1rem}
    .persona p{margin:.2rem 0 0;font-size:.85rem;color:var(--muted)}
    .persona .rol{color:var(--accent);font-weight:600;font-size:.8rem;margin:0}

    /* ---------- inversión y preinscripción ---------- */
    .cierre{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.05fr);gap:2rem;align-items:start}
    @media (max-width:860px){.cierre{grid-template-columns:1fr}}
    .precio{font-size:2.2rem;font-weight:800;letter-spacing:-.03em;line-height:1.1;margin:.2rem 0 .8rem}
    .bloque{padding:1.2rem 1.3rem;margin-bottom:1rem}
    .bloque h3{margin:0 0 .5rem;font-size:1.02rem}
    .bloque details summary{cursor:pointer;font-weight:600;color:var(--accent);margin-top:.6rem}
    .horas{font-size:1.5rem;font-weight:800;letter-spacing:-.02em;margin:0 0 .3rem}
    .reparto{list-style:none;padding:0;margin:.8rem 0 0;display:grid;gap:.35rem}
    .reparto li{display:flex;justify-content:space-between;gap:1rem;font-size:.9rem;padding:.35rem .6rem;border-radius:6px;background:var(--ground)}
    .reparto li b{font-weight:600}
    .como{counter-reset:paso;list-style:none;padding:0;margin:.6rem 0 0}
    .como li{counter-increment:paso;position:relative;padding:0 0 .9rem 2.3rem;font-size:.92rem}
    .como li::before{content:counter(paso);position:absolute;left:0;top:.05rem;width:1.6rem;height:1.6rem;border-radius:50%;
                     background:var(--accent);color:var(--surface);display:grid;place-items:center;font-size:.78rem;font-weight:700}
    .como span{color:var(--muted)}

    /* ---------- preguntas ---------- */
    .faq details{border-bottom:1px solid var(--rule);padding:.8rem 0}
    .faq summary{cursor:pointer;font-weight:600;list-style:none;display:flex;justify-content:space-between;gap:1rem}
    .faq summary::-webkit-details-marker{display:none}
    .faq summary::after{content:"+";color:var(--accent);font-weight:700}
    .faq details[open] summary::after{content:"−"}
    .faq details p{margin:.5rem 0 0;color:var(--ink-soft);font-size:.94rem}
    .enlaces{list-style:none;padding:0;margin:0;display:grid;gap:.4rem}
    .enlaces a{display:flex;justify-content:space-between;gap:1rem;text-decoration:none;padding:.6rem .8rem;border-radius:8px;
               border:1px solid var(--rule);background:var(--surface);color:var(--ink);font-size:.92rem}
    .enlaces a:hover{border-color:var(--accent);color:var(--accent)}
    .enlaces a::after{content:"↗";color:var(--accent)}
@endsection

@section('content')
<main class="fa">
    {{-- ================================================== portada --}}
    <nav class="indice" aria-label="En esta página">
        <a href="#programa">El programa</a>
        <a href="#proyectos">Proyectos</a>
        <a href="#laboratorio">Tu laboratorio</a>
        <a href="#comunidad">Comunidad global</a>
        <a href="#inversion">Inversión</a>
        <a href="#preguntas">Preguntas</a>
    </nav>

    <header class="fa-portada {{ $deFondo ? 'fondo' : ($videoPortada || $embedPortada || $imagenPortada ? '' : 'sola') }}">
        @if ($deFondo)
            {{-- Sin sonido y en bucle: es ambiente, no algo que haya que ver
                 entero. La imagen va de póster mientras carga. --}}
            <div class="capa" aria-hidden="true">
                @if ($videoPortada)
                    <video id="video-portada" autoplay muted loop playsinline preload="metadata"
                           @if ($imagenPortada) poster="{{ $imagenPortada }}" @endif src="{{ $videoPortada }}"></video>
                @elseif ($embedDeFondo)
                    @if ($imagenPortada)<img src="{{ $imagenPortada }}" alt="">@endif
                    <iframe id="video-portada" src="{{ $embedDeFondo }}" title="Video de Fab Academy" tabindex="-1"
                            allow="autoplay; encrypted-media; picture-in-picture"></iframe>
                @else
                    <img src="{{ $imagenPortada }}" alt="">
                @endif
            </div>
            <div class="in">
        @endif
        <div class="{{ $deFondo ? 'texto' : '' }}">
            @if (filled($fab['distintivo'] ?? null))
                <a class="distintivo" href="{{ $fab['nodos_url'] }}" target="_blank" rel="noopener">{{ $fab['distintivo'] }} ↗</a>
            @endif

            <h1>Fab Academy: aprende a fabricar <em>(casi) cualquier cosa</em></h1>

            <p class="lead">
                {{ $curso->summary ?: 'Un programa internacional de fabricación digital donde aprendes diseñando, construyendo, programando y documentando un proyecto propio.' }}
            </p>

            <div class="fila-botones" style="margin-top:1.4rem">
                @if ($cohorte?->admitePreinscripciones() && ! $abierta)
                    <a class="btn" href="#preinscripcion">Preinscribirme</a>
                @elseif ($abierta)
                    <a class="btn" href="{{ route('formacion') }}">Inscribirme</a>
                @endif
                <a class="btn secundario" href="#programa">Ver cómo funciona</a>
            </div>

            <dl class="datos">
                <div><dt>Inicio</dt><dd>{{ $abreEl ?? 'Por anunciar' }}</dd></div>
                <div><dt>Duración</dt><dd>{{ $pagina['duracion'] }}</dd></div>
                <div><dt>Modalidad</dt><dd>{{ $pagina['modalidad'] }} en {{ $ciudad }}</dd></div>
                <div><dt>Inversión</dt><dd>{{ $precio ?: 'Por anunciar' }}</dd></div>
            </dl>
        </div>

        @if ($deFondo)
            </div>

            @if ($videoPortada)
                <button type="button" class="sonido" id="sonido-portada" aria-pressed="false"><span class="icono">🔇</span> {{ $vp['rotulo'] ?: 'Activar sonido' }}</button>
            @elseif ($verConSonido)
                <a class="sonido" href="{{ $verConSonido }}" target="_blank" rel="noopener">▶ {{ $vp['rotulo'] ?: 'Ver el video con sonido' }}</a>
            @endif

            <script>
                (function () {
                    var video = document.getElementById('video-portada');
                    var boton = document.getElementById('sonido-portada');

                    // Quien pidió menos movimiento ve la imagen quieta.
                    if (video && window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                        if (video.tagName === 'VIDEO') { video.removeAttribute('autoplay'); video.pause(); }
                        else { video.remove(); }
                    }

                    if (boton && video && video.tagName === 'VIDEO') {
                        boton.addEventListener('click', function () {
                            video.muted = !video.muted;
                            if (!video.muted) { video.play(); }
                            boton.setAttribute('aria-pressed', String(!video.muted));
                            boton.querySelector('.icono').textContent = video.muted ? '🔇' : '🔊';
                        });
                    }
                })();
            </script>
        @elseif ($videoPortada || $embedPortada || $imagenPortada)
            <div class="marco">
                @if ($videoPortada)
                    <video controls playsinline preload="metadata" src="{{ $videoPortada }}" @if ($imagenPortada) poster="{{ $imagenPortada }}" @endif></video>
                @elseif (! $embedPortada)
                    <img src="{{ $imagenPortada }}" alt="Fab Academy en {{ $lab }}">
                @else
                    <iframe src="{{ $embedPortada }}" title="Video de Fab Academy" loading="lazy"
                            allow="accelerometer; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
                @endif
                @if (filled($vp['rotulo'] ?? null) && $videoPortada)
                    <div class="leyenda">{{ $vp['rotulo'] }}</div>
                @endif
            </div>
        @endif
    </header>

    {{-- ================================================== qué es --}}
    <section id="programa">
        <p class="rotulo">Qué es</p>
        <h2>¿Qué es Fab Academy?</h2>
        <p class="intro">
            Un programa de la Fab Foundation basado en el curso <em>How To Make (Almost) Anything</em> del
            Center for Bits and Atoms del MIT. Se dicta a la vez en laboratorios de todo el mundo, y aquí
            se cursa con nuestras máquinas y nuestros instructores. <a href="#comunidad">Conoce su origen</a>.
        </p>

        <div class="pasos">
            @foreach ($pagina['pasos'] as $paso)
                <article class="tarjeta paso">
                    <div class="img">
                        @if ($src = P::url($paso['imagen'] ?? null))
                            <img class="foto" src="{{ $src }}" alt="{{ $paso['titulo'] }}" loading="lazy">
                        @else
                            <div class="foto sinfoto">{{ mb_substr($paso['titulo'], 0, 1) }}</div>
                        @endif
                    </div>
                    <div class="cuerpo">
                        <h3>{{ $paso['titulo'] }}</h3>
                        <p>{{ $paso['texto'] ?? '' }}</p>
                    </div>
                </article>
            @endforeach
        </div>
    </section>

    {{-- ================================================== semana --}}
    <section>
        <p class="rotulo">El ritmo</p>
        <h2>Así se vive una semana</h2>
        <p class="intro">Cada semana es una técnica nueva, y algo hecho con ella.</p>

        <ol class="linea" style="--n:{{ max(1, count($pagina['semana'])) }}">
            @foreach ($pagina['semana'] as $n => $paso)
                <li>
                    <div class="punto">{{ $n + 1 }}</div>
                    <b>{{ $paso['titulo'] }}</b>
                    <span>{{ $paso['texto'] ?? '' }}</span>
                </li>
            @endforeach
        </ol>
    </section>

    {{-- ================================================== habilidades --}}
    <section>
        <p class="rotulo">Lo que vas a saber hacer</p>
        <h2>Tecnologías y habilidades</h2>
        <p class="intro">Una técnica por semana, agrupadas por lo que te permiten hacer.</p>

        <div class="categorias">
            @foreach ($pagina['categorias'] as $n => $cat)
                @php $temas = P::lineas($cat['temas'] ?? ''); @endphp
                <article class="tarjeta categoria" style="--tono:{{ $tonos[$n % count($tonos)] }}">
                    <div class="img">
                        @if ($src = P::url($cat['imagen'] ?? null))
                            <img class="foto" src="{{ $src }}" alt="{{ $cat['nombre'] }}" loading="lazy">
                        @else
                            <div class="sinfoto">{{ $cat['nombre'] }}</div>
                        @endif
                    </div>
                    <div class="cuerpo">
                        <h3>{{ $cat['nombre'] }}</h3>
                        <p class="primeros">{{ implode(' · ', array_slice($temas, 0, 2)) }}</p>
                        @if (count($temas) > 0)
                            <details>
                                <summary>Ver más</summary>
                                <ul>
                                    @foreach ($temas as $tema)
                                        <li>{{ $tema }}</li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    </section>

    {{-- ================================================== portafolio --}}
    <section>
        <div class="par">
            <div>
                <p class="rotulo">Documentación</p>
                <h2>Tu portafolio de fabricación digital</h2>
                <p>{{ $pagina['portafolio']['texto'] }}</p>
                @if (filled($pagina['portafolio']['url'] ?? null))
                    <a class="btn secundario" href="{{ $pagina['portafolio']['url'] }}" target="_blank" rel="noopener">Ver ejemplo de documentación ↗</a>
                @endif
            </div>
            @if ($src = P::url($pagina['portafolio']['imagen'] ?? null))
                <div class="img"><img class="foto" src="{{ $src }}" alt="Portafolio de un estudiante de Fab Academy" loading="lazy"></div>
            @endif
        </div>
    </section>

    {{-- ================================================== proyectos --}}
    <section id="proyectos">
        <p class="rotulo">Lo que se construye</p>
        <h2>Proyectos que inspiran</h2>
        <p class="intro">Al terminar, cada estudiante presenta un proyecto final que integra lo aprendido.</p>

        @if ($destacado)
            <article class="tarjeta destacado">
                <div class="img">
                    @if ($src = P::url($destacado['imagen'] ?? null))
                        <img class="foto" src="{{ $src }}" alt="{{ $destacado['nombre'] }}" loading="lazy">
                    @else
                        <div class="foto sinfoto">{{ mb_substr($destacado['nombre'], 0, 1) }}</div>
                    @endif
                </div>
                <div class="cuerpo">
                    <p class="rotulo">Proyecto final</p>
                    <h3>{{ $destacado['nombre'] }}</h3>
                    @if (filled($destacado['estudiante'] ?? null) || filled($destacado['anio'] ?? null))
                        <p class="meta">{{ collect([$destacado['estudiante'] ?? null, $destacado['anio'] ?? null])->filter()->implode(' · ') }}</p>
                    @endif
                    @if (filled($destacado['resumen'] ?? null))
                        <p>{{ $destacado['resumen'] }}</p>
                    @endif
                    @if (filled($destacado['tecnologias'] ?? null))
                        <ul class="chips">
                            @foreach (array_filter(array_map('trim', explode(',', $destacado['tecnologias']))) as $t)
                                <li>{{ $t }}</li>
                            @endforeach
                        </ul>
                    @endif
                    @if (filled($destacado['url'] ?? null))
                        <p style="margin:1rem 0 0"><a class="btn" href="{{ $destacado['url'] }}" target="_blank" rel="noopener">Ver documentación completa ↗</a></p>
                    @endif
                </div>
            </article>
        @endif

        @if ($resto->isNotEmpty())
            <div class="carrusel" tabindex="0" aria-label="Más proyectos">
                @foreach ($resto as $p)
                    <article class="tarjeta">
                        <div class="img">
                            @if ($src = P::url($p['imagen'] ?? null))
                                <img class="foto" src="{{ $src }}" alt="{{ $p['nombre'] }}" loading="lazy">
                            @else
                                <div class="foto sinfoto">{{ mb_substr($p['nombre'], 0, 1) }}</div>
                            @endif
                        </div>
                        <div class="cuerpo">
                            <h3>
                                @if (filled($p['url'] ?? null))
                                    <a href="{{ $p['url'] }}" target="_blank" rel="noopener">{{ $p['nombre'] }}</a>
                                @else
                                    {{ $p['nombre'] }}
                                @endif
                            </h3>
                            <p class="meta">{{ collect([$p['estudiante'] ?? null, $p['anio'] ?? null])->filter()->implode(' · ') }}</p>
                            @if (filled($p['tecnologias'] ?? null))
                                <ul class="chips">
                                    @foreach (array_filter(array_map('trim', explode(',', $p['tecnologias']))) as $t)
                                        <li>{{ $t }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        @endif

        <div class="fila-botones" style="margin-top:1.2rem">
            <a class="btn secundario" href="https://fabacademy.org/2026/highlights.html" target="_blank" rel="noopener">Proyectos destacados en el sitio oficial ↗</a>
            <a class="btn secundario" href="https://fabacademy.org/archive/" target="_blank" rel="noopener">Cohortes anteriores ↗</a>
        </div>
    </section>

    {{-- ================================================== grupales --}}
    <section>
        <div class="par al-reves">
            @if ($src = P::url($pagina['grupales']['imagen'] ?? null))
                <div class="img"><img class="foto" src="{{ $src }}" alt="Estudiantes trabajando en grupo" loading="lazy"></div>
            @endif
            <div>
                <p class="rotulo">En equipo</p>
                <h2>Actividades grupales</h2>
                <p>{{ $pagina['grupales']['texto'] }}</p>
                <ul class="chips">
                    @foreach (P::lineas($pagina['grupales']['ejemplos']) as $e)
                        <li>{{ $e }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    </section>

    {{-- ================================================== laboratorio --}}
    <section id="laboratorio">
        <p class="rotulo">Dónde se trabaja</p>
        <h2>Tu laboratorio: {{ $lab }}</h2>
        <p class="intro">{{ $vl['texto'] }}</p>

        @if ($src = P::url($vl['panoramica'] ?? null))
            <div class="panoramica"><img class="foto" src="{{ $src }}" alt="{{ $lab }}" loading="lazy"></div>
        @endif

        @if ($tecnologias->isNotEmpty())
            <div class="tecnologias">
                @foreach ($tecnologias as $t)
                    <figure class="tarjeta" style="margin:0">
                        <div class="img">
                            @if ($src = P::url($t['imagen'] ?? null))
                                <img class="foto" src="{{ $src }}" alt="{{ $t['nombre'] }}" loading="lazy">
                            @else
                                <div class="foto sinfoto">{{ mb_substr($t['nombre'], 0, 1) }}</div>
                            @endif
                        </div>
                        <p>{{ $t['nombre'] }}</p>
                    </figure>
                @endforeach
            </div>
        @endif

        @if ($videoLab || $embedLab)
            <div class="marco video-lab">
                @if ($videoLab)
                    <video controls playsinline preload="metadata" src="{{ $videoLab }}"></video>
                @else
                    <iframe src="{{ $embedLab }}" title="Recorrido por {{ $lab }}" loading="lazy"
                            allow="accelerometer; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
                @endif
            </div>
        @endif
    </section>

    {{-- ================================================== comunidad --}}
    <section id="comunidad">
        <p class="rotulo">Local y global</p>
        <h2>Un programa global</h2>

        <div class="tres">
            <div>
                <h3 style="margin-top:0">Origen y relación con el MIT</h3>
                <ol class="cadena">
                    <li><b>MIT Center for Bits and Atoms</b><span>Donde nace la idea de los Fab Labs.</span></li>
                    <li><b>How To Make (Almost) Anything</b><span>El curso de Neil Gershenfeld en el MIT.</span></li>
                    <li><b>Fab Academy</b><span>El programa global basado en ese curso, de la Fab Foundation.</span></li>
                    <li class="aqui"><b>{{ $lab }}</b><span>Nodo local en {{ $ciudad }}.</span></li>
                </ol>
                <p class="nota">Fab Academy se basa en el curso del MIT, pero el diploma lo otorga Fab Academy: no es un programa del MIT.</p>
            </div>

            <div>
                <h3 style="margin-top:0">Una red internacional</h3>
                @if ($src = P::url($pagina['comunidad']['imagen'] ?? null))
                    <div class="grad-img"><img class="foto" src="{{ $src }}" alt="La red Fab Lab" loading="lazy"></div>
                @endif
                <p>{{ $pagina['comunidad']['texto'] }}</p>
                <ul class="chips">
                    <li>Estudiantes</li><li>Instructores</li><li>Fab Labs</li><li>Makers</li><li>Investigadores</li><li>Aliados</li>
                </ul>
                <p style="margin:.9rem 0 0"><a href="{{ $fab['nodos_url'] }}" target="_blank" rel="noopener">Ver los laboratorios de la red ↗</a></p>
            </div>

            <div>
                <h3 style="margin-top:0">Graduación internacional</h3>
                @if ($src = P::url($pagina['graduacion']['imagen'] ?? null))
                    <div class="grad-img"><img class="foto" src="{{ $src }}" alt="Graduación de Fab Academy" loading="lazy"></div>
                @endif
                <p>{{ $pagina['graduacion']['texto'] }}</p>
                <p class="nota">{{ $pagina['graduacion']['nota'] }}</p>
            </div>
        </div>
    </section>

    {{-- ================================================== del interés al diploma --}}
    <section>
        <div class="cuatro">
            <div class="tarjeta">
                <h3>Proceso de aplicación</h3>
                <ol class="flujo">
                    @foreach (P::lineas($pagina['flujo']) as $paso)
                        <li>{{ $paso }}</li>
                    @endforeach
                </ol>
                <p class="nota">La preinscripción aquí no reemplaza el registro oficial en Fab Academy.
                    <a href="https://fabacademy.org/apply/registration.html" target="_blank" rel="noopener">Registro oficial ↗</a></p>
            </div>
            <div class="tarjeta">
                <h3>Lo que te llevas</h3>
                <ul class="checks">
                    @foreach (P::lineas($pagina['obtiene']) as $cosa)
                        <li>{{ $cosa }}</li>
                    @endforeach
                </ul>
            </div>
            <div class="tarjeta">
                <h3>Habilitación en {{ $lab }}</h3>
                <p style="font-size:.88rem;margin:0 0 .7rem;color:var(--ink-soft)">
                    Lo que apruebes dentro del programa te habilita para usar esas máquinas en el laboratorio después, según sus protocolos.
                </p>
                <ul class="insignias">
                    @foreach ($habilita as $h)
                        <li>{{ $h }}</li>
                    @endforeach
                </ul>
            </div>
            <div class="tarjeta">
                <h3>Dedicación</h3>
                <p class="horas">{{ $pagina['dedicacion']['horas'] }}</p>
                <ul class="reparto">
                    @foreach ($pagina['dedicacion']['reparto'] as $r)
                        <li><span>{{ $r['actividad'] }}</span><b>{{ $r['horas'] ?? '' }}</b></li>
                    @endforeach
                </ul>
                <p class="nota">{{ $pagina['dedicacion']['nota'] }}</p>
            </div>
        </div>
    </section>

    {{-- ================================================== equipo --}}
    @if ($equipo->isNotEmpty())
        <section>
            <p class="rotulo">Quién acompaña</p>
            <h2>Te acompañan en el proceso</h2>
            <div class="equipo">
                @foreach ($equipo as $p)
                    <article class="tarjeta persona">
                        <div class="img">
                            @if ($src = P::url($p['foto'] ?? null))
                                <img class="foto" src="{{ $src }}" alt="{{ $p['nombre'] }}" loading="lazy">
                            @else
                                <div class="foto sinfoto">{{ mb_substr($p['nombre'], 0, 1) }}</div>
                            @endif
                        </div>
                        <div>
                            @if (filled($p['rol'] ?? null))<p class="rol">{{ $p['rol'] }}</p>@endif
                            <h3>{{ $p['nombre'] }}</h3>
                            @if (filled($p['especialidad'] ?? null))<p><strong>{{ $p['especialidad'] }}</strong></p>@endif
                            @if (filled($p['experiencia'] ?? null))<p>{{ $p['experiencia'] }}</p>@endif
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    {{-- ================================================== inversión y preinscripción --}}
    <section id="inversion">
        <div class="cierre">
            <div>
                <p class="rotulo">Inversión</p>
                <h2>Lo que cuesta, y lo que incluye</h2>
                <div class="tarjeta bloque">
                    <h3>Valor del programa</h3>
                    <p class="precio">{{ $precio ?: 'Por anunciar' }}</p>
                    <ul class="checks">
                        @foreach (P::lineas($pagina['inversion']['incluye']) as $x)
                            <li>{{ $x }}</li>
                        @endforeach
                    </ul>
                    <details>
                        <summary>Qué no incluye y cómo financiarlo</summary>
                        <ul class="checks no" style="margin-top:.6rem">
                            @foreach (P::lineas($pagina['inversion']['no_incluye']) as $x)
                                <li>{{ $x }}</li>
                            @endforeach
                        </ul>
                        <p class="nota">{{ $pagina['inversion']['financiacion'] }}</p>
                        <p class="nota"><a href="https://fabacademy.org/apply/fees.html" target="_blank" rel="noopener">Costos oficiales de Fab Academy ↗</a></p>
                    </details>
                </div>

                <div class="tarjeta bloque">
                    <h3>Cómo funciona la preinscripción</h3>
                    <ol class="como">
                        <li><b>Te preinscribes.</b> <span>No pagas nada ni te comprometes: nos dices que te interesa y cómo lo financiarías.</span></li>
                        <li><b>Contamos cuántos somos.</b>
                            <span>La cohorte solo se abre si se junta gente suficiente{{ $cohorte?->minimum_to_open ? " —hacen falta {$cohorte->minimum_to_open}—" : "" }}.</span></li>
                        <li><b>Te escribimos.</b> <span>Si la cohorte abre, eres de los primeros en saberlo y en asegurar cupo.</span></li>
                    </ol>
                </div>
            </div>

            <div id="preinscripcion">
                @include('formacion._preinscripcion-ficha')
            </div>
        </div>
    </section>

    {{-- ================================================== preguntas --}}
    <section id="preguntas">
        <div class="par" style="align-items:start">
            <div class="faq">
                <p class="rotulo">Dudas</p>
                <h2>Preguntas frecuentes</h2>
                @foreach ($pagina['faqs'] as $f)
                    <details>
                        <summary>{{ $f['pregunta'] }}</summary>
                        <p>{{ $f['respuesta'] }}</p>
                    </details>
                @endforeach
            </div>
            <div>
                <p class="rotulo">Más allá de esta página</p>
                <h2>Explora Fab Academy</h2>
                <p class="intro">La información oficial del programa: calendario, currículo, requisitos y el trabajo de otros años.</p>
                <ul class="enlaces">
                    @foreach ($pagina['enlaces'] as $e)
                        @if (filled($e['url'] ?? null))
                            <li><a href="{{ $e['url'] }}" target="_blank" rel="noopener">{{ $e['texto'] }}</a></li>
                        @endif
                    @endforeach
                </ul>
            </div>
        </div>
    </section>
</main>
@endsection
