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

    /* ---------- cuantos somos ---------- */
    .conteo{
        background:var(--surface);border:1px solid var(--rule);border-radius:8px;
        padding:1.2rem 1.4rem;margin-bottom:1.2rem;
    }
    .conteo .numero{font-size:2.4rem;font-weight:800;letter-spacing:-.04em;line-height:1}
    .conteo .numero small{font-size:1rem;font-weight:500;color:var(--muted);letter-spacing:0}
    .barra{height:.5rem;border-radius:999px;background:color-mix(in srgb,var(--ink) 10%,transparent);
           overflow:hidden;margin:.8rem 0 .6rem}
    .barra i{display:block;height:100%;background:var(--accent);border-radius:999px}
    .conteo p{margin:0;font-size:.9rem;color:var(--muted)}

    /* ---------- formulario ---------- */
    .ficha{background:var(--surface);border:1px solid var(--rule);border-radius:8px;padding:1.4rem}
    .ficha h2{margin-bottom:.2rem}
    .ficha label{display:block;font-size:.84rem;font-weight:600;margin-top:1rem}
    .ficha input:not([type=checkbox]),.ficha select,.ficha textarea{
        display:block;width:100%;margin-top:.35rem;padding:.6rem .7rem;font:inherit;
        background:var(--ground);color:var(--ink);border:1px solid var(--rule);border-radius:4px;
    }
    .ficha input:focus,.ficha select:focus,.ficha textarea:focus{outline:2px solid var(--accent);outline-offset:1px}
    .ficha .help{display:block;font-weight:400;color:var(--muted);font-size:.8rem;margin-top:.3rem}
    .ficha label.casilla{font-weight:400;display:flex;gap:.6rem;align-items:flex-start}
    .ficha label.casilla input{margin-top:.25rem;flex:none}
    .ficha button{margin-top:1.2rem;width:100%;padding:.8rem;font-size:1rem}
    .aviso{background:color-mix(in srgb,#0D6E63 12%,transparent);border-radius:6px;
           padding:.8rem 1rem;margin-bottom:1rem;font-size:.92rem}
    .error{background:color-mix(in srgb,#9B2C2C 12%,transparent);border-radius:6px;
           padding:.8rem 1rem;margin-bottom:1rem;font-size:.92rem}
    .error ul{margin:0;padding-left:1.1rem}
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
            @if ($cohorte)
                <div class="conteo">
                    <div class="numero">
                        {{ $somos }}
                        <small>
                            {{ $somos === 1 ? 'persona preinscrita' : 'personas preinscritas' }}
                            @if ($cohorte->minimum_to_open) de {{ $cohorte->minimum_to_open }} necesarias @endif
                        </small>
                    </div>

                    @if ($cohorte->minimum_to_open)
                        <div class="barra" role="img"
                             aria-label="{{ $somos }} de {{ $cohorte->minimum_to_open }}">
                            <i style="width:{{ min(100, (int) round($somos / $cohorte->minimum_to_open * 100)) }}%"></i>
                        </div>
                        <p>
                            @if ($faltan > 0)
                                {{ $faltan === 1 ? 'Falta una persona' : 'Faltan ' . $faltan . ' personas' }}
                                para abrir la cohorte {{ $cohorte->code }}.
                            @else
                                Se alcanzó el mínimo: estamos preparando la apertura de la cohorte.
                            @endif
                        </p>
                    @endif

                    @if ($cohorte->preenroll_until)
                        <p style="margin-top:.4rem">Preinscripciones hasta el {{ $cohorte->preenroll_until->format('d/m/Y') }}.</p>
                    @endif
                </div>
            @endif

            @if (session('status'))
                <div class="aviso">{{ session('status') }}</div>
            @endif

            @if ($abierta)
                {{-- Ya abrió: lo que toca es inscribirse de verdad. --}}
                <div class="ficha">
                    <h2>La cohorte ya abrió</h2>
                    <p>
                        {{ $abierta->code }} está recibiendo inscripciones
                        @if ($abierta->starts_on) y empieza el {{ $abierta->starts_on->format('d/m/Y') }}@endif.
                        Quedan {{ $abierta->cuposLibres() }} de {{ $abierta->capacity }} cupos.
                    </p>
                    <a class="btn" href="{{ route('formacion') }}">Ir a inscribirme</a>
                </div>
            @elseif (! $cohorte)
                <div class="ficha">
                    <h2>Todavía no hay cohorte anunciada</h2>
                    <p style="margin-bottom:0">
                        Cuando planeemos la próxima, aquí mismo se abre la preinscripción.
                        Vuelve pronto, o pregunta por ella en el laboratorio.
                    </p>
                </div>
            @elseif (! $cohorte->admitePreinscripciones())
                <div class="ficha">
                    <h2>Preinscripción cerrada</h2>
                    <p style="margin-bottom:0">{{ $cohorte->porQueNoAdmitePreinscripciones() }}</p>
                </div>
            @else
                @if ($errors->any())
                    <div class="error">
                        <ul>
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('preinscripcion.store', $curso) }}" class="ficha">
                    @csrf

                    <h2>Preinscríbete</h2>
                    <p class="help" style="color:var(--muted);font-size:.88rem;margin:0">
                        @if ($yaEstoy)
                            Ya estás en la lista. Si vuelves a enviar el formulario, corrige lo anterior en vez de duplicarlo.
                        @else
                            Dos minutos. Sin pago y sin compromiso.
                        @endif
                    </p>

                    {{-- Trampa para robots: nadie la ve, nadie debería llenarla. --}}
                    <div style="position:absolute;left:-9999px" aria-hidden="true">
                        <label>No llenar este campo
                            <input type="text" name="sitio_web" tabindex="-1" autocomplete="off">
                        </label>
                    </div>

                    <label>
                        Nombre completo
                        <input type="text" name="nombre" required maxlength="160" autocomplete="name"
                               value="{{ old('nombre', auth()->user()?->name) }}">
                    </label>

                    <label>
                        Correo
                        <input type="email" name="correo" required maxlength="160" autocomplete="email"
                               value="{{ old('correo', auth()->user()?->email) }}">
                        <span class="help">Aquí te avisamos si la cohorte se abre.</span>
                    </label>

                    {{-- Con indicativo: a un nodo único en el país le escribe gente
                         de fuera, y un número sin país no se puede marcar. --}}
                    @include('partials.telefono', ['valor' => auth()->user()?->phone])

                    <label>
                        Ciudad
                        <input type="text" name="ciudad" required maxlength="120"
                               value="{{ old('ciudad') }}" placeholder="Bogotá">
                        <span class="help">El trabajo de cada semana es presencial, en el laboratorio.</span>
                    </label>

                    <label>
                        A qué te dedicas
                        <input type="text" name="ocupacion" required maxlength="160"
                               value="{{ old('ocupacion') }}" placeholder="Diseñadora industrial, docente, ingeniero…">
                    </label>

                    <label>
                        Empresa o universidad
                        <input type="text" name="institucion" maxlength="160" value="{{ old('institucion') }}">
                        <span class="help">Si vienes de parte de una, o si sería la que lo paga.</span>
                    </label>

                    <label>
                        Por qué quieres hacerlo
                        <textarea name="motivacion" rows="4" required maxlength="2000">{{ old('motivacion') }}</textarea>
                        <span class="help">Qué quieres aprender o qué te gustaría construir. No hace falta que sea largo.</span>
                    </label>

                    <label>
                        Cómo piensas financiarlo
                        <select name="financiacion" required>
                            <option value="">Elige una</option>
                            @foreach (\App\Models\Preenrollment::FINANCIACION as $clave => $texto)
                                <option value="{{ $clave }}" @selected(old('financiacion') === $clave)>{{ $texto }}</option>
                            @endforeach
                        </select>
                        <span class="help">No te compromete a nada: nos dice si la cohorte es viable y qué apoyos buscar.</span>
                    </label>

                    <label>
                        Portafolio o LinkedIn
                        <input type="url" name="portafolio" maxlength="255" value="{{ old('portafolio') }}"
                               placeholder="https://">
                        <span class="help">Si tienes. No es requisito.</span>
                    </label>

                    {{-- Ley 1581 de 2012. Con el para qué escrito: una
                         autorización que no dice para qué no autoriza gran cosa. --}}
                    <label class="casilla">
                        <input type="checkbox" name="autoriza" value="1" required {{ old('autoriza') ? 'checked' : '' }}>
                        <span>
                            Autorizo a {{ config('fabos.lab.name') }} a guardar estos datos y a escribirme
                            sobre la apertura de esta cohorte. Puedo pedir que los borren cuando quiera.
                        </span>
                    </label>

                    <x-captcha accion="preinscripcion.store"/>

                    <button type="submit" class="btn">Preinscribirme</button>
                </form>
            @endif
        </div>
    </section>
</main>
@endsection
