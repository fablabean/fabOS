@extends('layouts.publico')
@section('title', 'Reservas · ' . config('fabos.lab.name'))

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

    /* Los tres caminos. Antes de saber QUE maquina hay que saber COMO: con
       alguien al lado, encargandolo, o por tu cuenta. */
    .caminos{display:grid;grid-template-columns:repeat(auto-fit,minmax(15rem,1fr));
             gap:1rem;margin:0 0 2.4rem}
    .camino{
        display:block;padding:1.3rem 1.4rem;border-radius:8px;text-decoration:none;
        border:1px solid var(--rule);background:var(--surface);color:inherit;
        transition:border-color .12s,transform .12s;
    }
    .camino:hover{border-color:var(--accent);transform:translateY(-2px)}
    .camino.puesto{border-color:var(--accent);box-shadow:inset 0 0 0 1px var(--accent)}
    /* La ilustracion de cada camino: un icono de linea, en el color de la
       marca, que se lee antes que el titulo. Va en linea en el HTML y no como
       archivo: son cuatro trazos y asi toman el color del tema. */
    .camino .ilus{display:block;width:3rem;height:3rem;margin-bottom:.8rem;color:var(--accent)}
    .camino .ilus svg{width:100%;height:100%;display:block}
    .camino b{display:block;font-size:1.15rem;margin-bottom:.35rem}
    .camino span{font-size:.86rem;color:var(--ink-soft);line-height:1.45;display:block}
    .camino .pie{display:block;margin-top:.6rem;font-size:.75rem;color:var(--muted);
                 font-family:ui-monospace,Consolas,monospace;letter-spacing:.06em;
                 text-transform:uppercase}

    /* Las areas, con foto: «impresion 3D» se reconoce de un vistazo; «Prusa
       MK4» no, si nunca has entrado. */
    .areas{display:grid;grid-template-columns:repeat(auto-fill,minmax(13rem,1fr));gap:1rem}
    .area{position:relative;display:block;border-radius:8px;overflow:hidden;
          text-decoration:none;color:#fff;background:var(--ground);border:1px solid var(--rule)}
    .area:hover{border-color:var(--accent)}
    .area img{aspect-ratio:16/10;width:100%;object-fit:cover;display:block;filter:brightness(.62)}
    .area .sinfoto{aspect-ratio:16/10;background:#1d2b28}
    .area .txt{position:absolute;left:0;right:0;bottom:0;padding:.8rem .9rem;
               background:linear-gradient(to top,rgba(0,0,0,.72),rgba(0,0,0,0))}
    .area .txt b{display:block;font-size:1rem}
    .area .txt span{font-size:.76rem;opacity:.85}

    .volver{display:inline-block;margin-bottom:1rem;font-size:.85rem;color:var(--ink-soft);
            text-decoration:none}
    .volver:hover{color:var(--accent)}
    .aviso{background:var(--surface);border:1px solid var(--rule);border-radius:8px;
           padding:1.2rem 1.3rem;margin-bottom:2rem}
    .aviso p{margin:0 0 .6rem;color:var(--ink-soft);font-size:.9rem;line-height:1.55}
    .aviso p:last-child{margin-bottom:0}
    .vacio{color:var(--muted);padding:1.4rem 0 2.4rem}
    /* La miga de pan: dice dónde estás y deja volver sin el botón del
       navegador, que en el teléfono nadie usa. */
    .migas{display:flex;flex-wrap:wrap;gap:.4rem;align-items:baseline;
           font-size:.82rem;color:var(--muted);margin:0 0 .6rem;
           font-family:ui-monospace,Consolas,monospace;letter-spacing:.04em;
           text-transform:uppercase}
    .migas a{color:var(--muted);text-decoration:none}
    .migas a:hover{color:var(--accent)}
    .migas strong{color:var(--ink-soft);font-weight:600}
    /* El camino recomendado por la guía: se ve desde lejos, y dice «te toca». */
    .camino.recomendado{outline:3px solid var(--accent);outline-offset:2px;position:relative}
    .camino.recomendado::before{
        content:"Te toca";position:absolute;top:-.7rem;left:1rem;background:var(--accent);color:var(--surface);
        font-family:ui-monospace,Consolas,monospace;font-size:.66rem;letter-spacing:.14em;text-transform:uppercase;
        padding:.2rem .6rem;border-radius:999px;
    }
    .camino:not(.recomendado){transition:opacity .2s}
    .caminos:has(.recomendado) .camino:not(.recomendado){opacity:.55}
    .caminos:has(.recomendado) .camino:not(.recomendado):hover{opacity:1}
    /* El banner de la guía: a lo ancho, con su texto encima. */
    .mapa{position:relative}
    .mapa img{display:block;width:100%;max-height:26rem;object-fit:cover;border-radius:8px;border:1px solid var(--rule)}
    .mapa p{margin:.6rem 0 0;color:var(--ink-soft);font-size:1.02rem}
    .reservas-mias{display:grid;gap:.5rem;margin-bottom:2rem}
    .mia{display:flex;flex-wrap:wrap;gap:.2rem 1rem;align-items:baseline;
         padding:.7rem .9rem;border:1px solid var(--rule);border-radius:6px;
         background:var(--surface)}
    .mia b{font-size:.95rem}
    .mia span{font-size:.84rem;color:var(--muted);font-variant-numeric:tabular-nums}
    /* Elegir varias herramientas: la casilla encima de la tarjeta, y una
       barra fija abajo mientras haya algo marcado. */
    .con-casilla{position:relative}
    .con-casilla.elegida .equipo{outline:2px solid var(--accent)}
    .casilla{
        position:absolute;top:.6rem;right:.6rem;z-index:2;display:flex;align-items:center;gap:.35rem;
        background:var(--surface);border:1px solid var(--rule);border-radius:999px;
        padding:.25rem .6rem .25rem .45rem;font-size:.78rem;font-weight:600;cursor:pointer;
    }
    .casilla input{margin:0;accent-color:var(--accent)}
    .casilla:has(input:disabled){opacity:.45;cursor:not-allowed}
    .barra-varias{
        position:sticky;bottom:0;z-index:5;display:flex;flex-wrap:wrap;align-items:center;gap:1rem;
        justify-content:space-between;margin:1rem 0 0;padding:.8rem 1.1rem;
        background:color-mix(in srgb,var(--surface) 94%,transparent);backdrop-filter:blur(8px);
        border:1px solid var(--rule);border-radius:8px;box-shadow:0 -6px 24px rgba(0,0,0,.08);
    }
    .barra-varias .cuenta{font-weight:600}
@endsection

@section('content')
<main>
    @php
        $enlaceDeArea = fn (string $slug) => route('publico.reservas', array_filter([
            'modo' => $modo ?: null,
            'area' => $slug,
        ]));
    @endphp

    @php
        $nombreDelModo = [
            'asesoria'     => 'Asesoría',
            'autonomia'    => 'Hago mi pieza',
            'espacio'      => 'Espacio y herramientas',
            'herramientas' => 'Herramientas',
        ][$modo] ?? null;
        // En la lista de herramientas no se pasa por el área: se va derecho.
        $enLaLista = $area !== '' || $modo === 'herramientas';
        $nombreDelArea = $areas->firstWhere('slug', $area)['nombre'] ?? null;
    @endphp

    {{-- ------------------------------------------------------ dónde estoy
         Al avanzar, lo ya elegido se encoge a una línea. Antes seguían ahí las
         tres tarjetas grandes, la línea del espacio y las reservas propias: la
         página cambiaba de verdad, pero lo nuevo nacía por debajo del pliegue y
         parecía que el clic no había hecho nada. --}}
    @if ($modo === '')
        @php
            $guia = session('guia');
            $recomendado = $guia['camino'] ?? null;
            // Espacio y herramientas comparten tarjeta.
            $tarjetaRecomendada = in_array($recomendado, ['espacio', 'herramientas'], true) ? 'espacio' : $recomendado;
            $imagenGuia = \App\Support\Settings::imagenDeLaGuia();
        @endphp

        {{-- El banner (§10): una foto que ayude a reconocer el camino sin
             preguntar. Se sube en Comunicaciones → Guía de reservas; sin
             foto, no se pinta. --}}
        @if ($imagenGuia)
            <section class="mapa" style="padding:1.6rem 0 0">
                <img src="{{ asset('storage/' . $imagenGuia) }}" alt="Cómo usar el laboratorio" loading="eager">
                @if (\App\Support\Settings::textoDeLaGuia())
                    <p>{{ \App\Support\Settings::textoDeLaGuia() }}</p>
                @endif
            </section>
        @endif

        <section style="padding-bottom:1rem">
            <p class="rotulo">Reservas</p>
            <h1>¿Cómo quieres usar el laboratorio?</h1>
            <p class="lead">
                Hay cuatro maneras, y conviene elegir antes de mirar máquinas: cambia lo que
                necesitas y lo que tienes que hacer.
            </p>
        </section>

        {{-- La guía va ANTES de los caminos: quien no sabe cuál es el suyo no
             debería tener que leer las cuatro tarjetas para descubrir que hay
             quien se lo dice. --}}
        @if (app(\App\Services\Ia\GuiaDeReservas::class)->disponible())
            @include('publico.guia')
        @endif

        {{-- Los cuatro caminos. Cada uno con su ilustración, porque el dibujo
             se lee antes que el título y a quien llega por primera vez le dice
             de qué va sin leer. El prototipado asistido sale del catálogo: no
             es una máquina que se reserve, es un encargo que se propone. Y el
             espacio es un camino más: antes iba en una línea debajo y nadie
             lo veía. --}}
        <div class="caminos">
            <a class="camino {{ $tarjetaRecomendada === 'asesoria' ? 'recomendado' : '' }}" id="camino-asesoria" href="{{ route('publico.reservas', ['modo' => 'asesoria']) }}">
                <span class="ilus" aria-hidden="true">
                    {{-- Dos personas: una acompaña a la otra. --}}
                    <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="18" cy="15" r="6"/><path d="M6 40v-4a10 10 0 0 1 10-10h4a10 10 0 0 1 10 10v4"/>
                        <circle cx="34" cy="17" r="4.5"/><path d="M33 26h2a8 8 0 0 1 8 8v6"/>
                    </svg>
                </span>
                <b>Asesoría</b>
                <span>Recibes una explicación personalizada para que conozcas cómo sacarle
                      todo el jugo al fablab. No incluye producción de piezas, pero sí te
                      podemos ayudar a entender cómo es el proceso productivo.</span>
                <span class="pie">Reservas un acompañamiento</span>
            </a>

            <a class="camino {{ $tarjetaRecomendada === 'proyecto' ? 'recomendado' : '' }}" id="camino-proyecto" href="{{ route('proyectos.solicitar') }}">
                <span class="ilus" aria-hidden="true">
                    {{-- Una impresora sacando una pieza: lo ejecutamos con ella. --}}
                    <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="8" y="6" width="32" height="10" rx="2"/><path d="M14 16v6M34 16v6"/>
                        <path d="M18 22h12l4 8v10H14V30z"/><path d="M14 30h20"/><path d="M24 14v4"/>
                    </svg>
                </span>
                <b>Fabricación y acompañamiento técnico</b>
                <span>No operas tú solo: nos cuentas qué necesitas (fabricar una pieza,
                      configurar un equipo o personalizar un software) y nuestro equipo
                      te asiste y ejecuta el proceso.</span>
                <span class="pie">Propones un proyecto</span>
            </a>

            <a class="camino {{ $tarjetaRecomendada === 'autonomia' ? 'recomendado' : '' }}" id="camino-autonomia" href="{{ route('publico.reservas', ['modo' => 'autonomia']) }}">
                <span class="ilus" aria-hidden="true">
                    {{-- Una pieza y una herramienta: la haces tú. --}}
                    <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10 18l14-8 14 8v14l-14 8-14-8z"/><path d="M10 18l14 8 14-8M24 26v14"/>
                        <path d="M36 8l6 6-4 4-6-6z"/>
                    </svg>
                </span>
                <b>Hago mi pieza</b>
                <span>Reservas y operas por tu cuenta, en los equipos donde ya tienes
                      certifab.</span>
                <span class="pie">Reservas la máquina</span>
            </a>

            {{-- Público, a diferencia de /espacios: la bifurcación se puede
                 mirar sin cuenta, y la cuenta se pide al reservar. --}}
            <a class="camino {{ $tarjetaRecomendada === 'espacio' ? 'recomendado' : '' }}" id="camino-espacio" href="{{ route('publico.reservas', ['modo' => 'espacio']) }}">
                <span class="ilus" aria-hidden="true">
                    {{-- Una sala con su puerta y su mesa, y una llave: el grupo y las herramientas. --}}
                    <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M6 42V12l18-6v36"/><path d="M24 12h18v30"/><path d="M6 42h36"/>
                        <path d="M30 22h8"/><circle cx="19" cy="26" r="1.5" fill="currentColor"/>
                        <circle cx="33" cy="33" r="3"/><path d="M35.5 30.5l5-5M38 28l2 2"/>
                    </svg>
                </span>
                <b>Espacio y herramientas</b>
                <span>Una sala o un taller con las herramientas que hay dentro, para trabajar
                      en grupo o dar una clase. O solo unas herramientas, para usarlas
                      donde estés.</span>
                <span class="pie">Reservas un espacio o herramientas</span>
            </a>
        </div>

        {{-- La franja de hoy, que es lo que decide si una sala se confirma sola. --}}
        <p class="lead" style="margin:-1.4rem 0 2rem">
            @if ($franjaHoy)
                Hoy el laboratorio atiende de <strong>{{ substr($franjaHoy[0], 0, 5) }}</strong>
                a <strong>{{ substr($franjaHoy[1], 0, 5) }}</strong>.
            @else
                Hoy no hay personal en jornada, así que lo que requiere acompañamiento no se
                puede reservar.
            @endif
        </p>

        {{-- Lo que ya tiene pedido: antes de reservar otra cosa, saber qué hay. --}}
        @if ($misReservas->isNotEmpty())
            <section style="padding-top:0">
                <p class="rotulo">Mis próximas reservas</p>
                <div class="reservas-mias">
                    @foreach ($misReservas as $r)
                        <div class="mia">
                            <b>
                                @if ($r->esAsesoria())
                                    Asesoría · {{ $r->sobreQue() ?? 'con el equipo' }}
                                @else
                                    {{ $r->reservable?->name ?? 'Reserva' }}
                                @endif
                            </b>
                            <span>
                                {{ $r->starts_at->timezone(config('fabos.lab.timezone'))->format('d/m/Y H:i') }}
                                — {{ $r->ends_at->timezone(config('fabos.lab.timezone'))->format('H:i') }}
                                @if ($r->supervisor) · acompaña {{ $r->supervisor->name }} @endif
                            </span>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    @else
        {{-- Ya se eligió: una miga de pan y directo a la pregunta siguiente. --}}
        <nav class="migas" aria-label="Dónde estás">
            <a href="{{ route('publico.reservas') }}">Reservas</a>
            <span aria-hidden="true">›</span>
            @if ($nombreDelArea)
                <a href="{{ route('publico.reservas', ['modo' => $modo]) }}">{{ $nombreDelModo }}</a>
                <span aria-hidden="true">›</span>
                <strong>{{ $nombreDelArea }}</strong>
            @else
                <strong>{{ $nombreDelModo }}</strong>
            @endif
        </nav>

        <h1 style="margin-bottom:.3rem">
            @if ($modo === 'espacio')
                ¿La sala entera, o solo herramientas?
            @elseif ($modo === 'herramientas')
                Herramientas
            @elseif ($area === '')
                {{ $modo === 'asesoria' ? '¿Sobre qué área?' : '¿Qué vas a reservar?' }}
            @elseif ($modo === 'asesoria' && ! $eligiendoMaquina)
                ¿Una máquina concreta, o el área entera?
            @else
                {{ $nombreDelArea }}
            @endif
        </h1>
    @endif

    {{-- ------------------------------------------------------------ autonomía --}}
    @if ($modo === 'autonomia' && $area === '')
        <div class="aviso">
            <p>
                <strong>La autonomía se gana por equipo.</strong> El <em>certifab</em> es la
                habilitación de una familia de máquinas: se consigue en una formación o en una
                asesoría, y desde entonces reservas esa máquina tú solo.
            </p>
            @auth
                <p>
                    Abajo está <strong>solo lo que puedes reservar hoy</strong>. Lo que no
                    aparece es porque todavía te falta el certifab —o el equipo está fuera de
                    servicio—. Para eso está la <a href="{{ route('publico.reservas', ['modo' => 'asesoria']) }}">asesoría</a>.
                </p>
            @else
                <p>
                    Para saber qué puedes reservar hace falta que entres: depende de tus
                    certifabs. <a href="{{ route('login') }}">Ingresar</a>.
                </p>
            @endauth
        </div>
    @endif

    @if ($modo === 'asesoria' && $area === '')
        <div class="aviso">
            <p>
                <strong>No hace falta que sepas operar la máquina.</strong> Eliges el equipo,
                reservas una franja y alguien del laboratorio está contigo. Es también la
                forma de conseguir el certifab: se aprende usándola.
            </p>
            @guest
                {{-- Decirlo antes y no al final: enterarse de que hace falta cuenta
                     justo al pulsar «reservar» es donde se abandona. --}}
                <p>
                    Para apartar la franja necesitas una cuenta.
                    <a href="{{ route('login') }}">Ingresar</a>.
                </p>
            @endguest
        </div>
    @endif

    {{-- ------------------------------------------------ espacio y herramientas --}}
    {{-- Dos cosas distintas que antes eran una: la sala con lo que hay dentro,
         y las herramientas solas. Quien viene por un taladro no quiere reservar
         un taller, y quien reserva el taller marca el taladro ahí dentro. --}}
    @if ($modo === 'espacio')
        <div class="caminos">
            <a class="camino" href="{{ route('espacios.index') }}">
                <b>Un espacio</b>
                <span>Una sala, un taller o el laboratorio entero, para trabajar en grupo,
                      dar una clase o hacer un recorrido. Al reservarlo marcas las
                      herramientas que vas a necesitar dentro.</span>
                <span class="pie">Reservas la sala</span>
            </a>

            <a class="camino" href="{{ route('publico.reservas', ['modo' => 'herramientas']) }}">
                <b>Solo herramientas</b>
                <span>Un taladro, unas gafas de realidad virtual, un cautín. Las portátiles
                      te las llevas a donde vayas a trabajar; las demás se usan en su
                      sitio.</span>
                <span class="pie">Ves toda la lista</span>
            </a>
        </div>
    @endif

    @if ($modo === 'herramientas')
        <div class="aviso">
            <p>
                <strong>Todas las herramientas que se prestan</strong>, sin pasar por áreas:
                son pocas y se buscan por nombre. Las marcadas como <em>portátil</em> se
                llevan a cualquier parte; las demás se usan en el espacio donde están. Si vas
                a trabajar en una sala, mejor
                <a href="{{ route('espacios.index') }}">reserva el espacio</a> y márcalas dentro.
            </p>
            @guest
                <p>Para reservar una necesitas una cuenta. <a href="{{ route('login') }}">Ingresar</a>.</p>
            @endguest
        </div>
    @endif

    {{-- --------------------------------------------------- elegir área, o la lista --}}
    {{-- Las áreas solo cuando ya se eligió el camino: preguntar «qué área»
         antes de saber si vas a que te acompañen o a reservar tú es el segundo
         paso antes del primero. El área no significa lo mismo en cada caso. --}}
    @if ($modo === '' || $modo === 'espacio')
        {{-- Nada más. Los caminos de arriba son toda la pregunta. --}}
    @elseif (! $enLaLista)
        @if ($areas->isNotEmpty())
            <section style="padding-top:.4rem">
                <p class="rotulo">Elige un área · {{ $total }} equipos</p>
                <div class="areas">
                    @foreach ($areas as $a)
                        <a class="area" href="{{ $enlaceDeArea($a['slug']) }}">
                            @if ($a['foto'])
                                <img src="{{ $a['foto'] }}" alt="{{ $a['nombre'] }}" loading="lazy">
                            @else
                                <div class="sinfoto"></div>
                            @endif
                            <div class="txt">
                                <b>{{ $a['nombre'] }}</b>
                                <span>{{ $a['cuantos'] }} {{ $a['cuantos'] === 1 ? 'equipo' : 'equipos' }}</span>
                            </div>
                        </a>
                    @endforeach
                </div>
            </section>
        @elseif ($modo === 'autonomia' && $identificada)
            <p class="vacio">
                Todavía no tienes certifabs vigentes, así que no hay nada que puedas reservar
                por tu cuenta. Empieza por una
                <a href="{{ route('publico.reservas', ['modo' => 'asesoria']) }}">asesoría</a>.
            </p>
        @endif
    @else
        @if ($area !== '')
            <a class="volver" href="{{ route('publico.reservas', array_filter(['modo' => $modo ?: null])) }}">
                ← Todas las áreas
            </a>
        @endif

        {{-- Elegida el área, la siguiente pregunta: ¿general o de una máquina?
             Son dos consultas distintas, y quien viene sin saber qué máquina
             necesita no debería tener que elegir una para poder preguntar. --}}
        @if ($modo === 'asesoria' && ! $eligiendoMaquina)
            <div class="caminos">
                @if ($asesoriaGeneral)
                    <a class="camino" href="{{ route('asesoria.area.show', $asesoriaGeneral) }}">
                        <b>General del área</b>
                        <span>No sabes todavía qué máquina necesitas. Alguien te escucha, te dice
                              con qué se hace lo que quieres y te acompaña.</span>
                        <span class="pie">Sin elegir equipo</span>
                    </a>
                @endif

                <a class="camino" href="{{ route('publico.reservas', [
                        'modo' => 'asesoria', 'area' => $area, 'maquina' => 1,
                   ]) }}">
                    <b>Sobre una máquina</b>
                    <span>Ya sabes cuál: eliges el equipo y reservas la franja con quien te
                          va a acompañar.</span>
                    <span class="pie">Eliges el equipo</span>
                </a>
            </div>
        @endif

        {{-- Dentro del área, la pregunta de quien ya está de pie en la puerta. --}}
        <p style="margin:0 0 1rem" @if (! $eligiendoMaquina) hidden @endif>
            <a class="volver"
               href="{{ route('publico.reservas', array_filter([
                    'modo'   => $modo ?: null,
                    'area'   => $area,
                    'libres' => $soloLibres ? null : 1,
               ])) }}"
               style="{{ $soloLibres ? 'color:var(--accent);font-weight:600' : '' }}">
                {{ $soloLibres ? '✓ ' : '' }}Solo lo libre ahora ({{ $libres }})
            </a>
        </p>

        {{-- En herramientas, la lista es también un formulario: se marcan
             varias y se reservan juntas. Las casillas van FUERA del enlace de
             la tarjeta, que sigue llevando a la ficha de cada una. --}}
        @if ($modo === 'herramientas')
            <form method="GET" action="{{ route('reservas.herramientas') }}" id="varias"
                  data-tope="{{ $maxHerramientas }}">
        @endif

        @foreach ($eligiendoMaquina ? $porArea : [] as $nombreArea => $equipos)
            <section id="{{ $equipos->first()->area?->slug }}">
                <p class="rotulo">{{ $nombreArea }} · {{ $equipos->count() }}</p>
                <div class="equipos">
                    @foreach ($equipos as $e)
                        @if ($modo === 'herramientas')
                        <div class="con-casilla">
                            <label class="casilla" title="Añadir a la reserva">
                                <input type="checkbox" name="h[]" value="{{ $e->id }}"
                                       @checked(in_array($e->id, $marcadas, true))>
                                <span>Elegir</span>
                            </label>
                        @endif
                        {{-- En asesoría se va derecho a pedir el acompañamiento, si el
                             equipo tiene asesores declarados. En lo demás, a la ficha. --}}
                        {{-- A donde lleva cada máquina depende del camino:
                             en asesoría, a pedir el acompañamiento; en
                             autonomía, derecho a reservarla; sin camino
                             elegido, a su ficha. --}}
                        <a class="equipo"
                           href="{{ match (true) {
                                $modo === 'asesoria' && $e->advisors_count > 0 => route('asesoria.show', $e),
                                $modo === 'autonomia', $modo === 'herramientas' => route('reservas.show', $e),
                                default => route('publico.equipo', $e),
                           } }}">
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
                                <span>
                                    {{ $e->riskFamily?->name }}
                                    {{-- En la lista de herramientas, lo que decide a dónde
                                         te la puedes llevar. --}}
                                    @if ($modo === 'herramientas')
                                        @if ($e->puede_salir)
                                            · portátil
                                        @elseif ($e->space)
                                            · en {{ $e->space->name }}
                                        @endif
                                    @endif
                                </span>
                            </div>
                        </a>
                        @if ($modo === 'herramientas')
                        </div>
                        @endif
                    @endforeach
                </div>
            </section>
        @endforeach

        @if ($modo === 'herramientas')
                {{-- La barra aparece al marcar la primera. Cuenta, avisa del
                     tope y lleva a elegir la hora para todas. --}}
                <div class="barra-varias" hidden>
                    <span class="cuenta"></span>
                    <button type="submit" class="btn">Reservar juntas</button>
                </div>
            </form>

            <script>
                (function () {
                    var form = document.getElementById('varias');
                    var tope = parseInt(form.dataset.tope, 10) || 5;
                    var barra = form.querySelector('.barra-varias');
                    var cuenta = barra.querySelector('.cuenta');
                    var casillas = form.querySelectorAll('input[name="h[]"]');

                    function pinta() {
                        var marcadas = form.querySelectorAll('input[name="h[]"]:checked').length;
                        barra.hidden = marcadas === 0;
                        cuenta.textContent = marcadas === 1
                            ? '1 herramienta elegida'
                            : marcadas + ' herramientas elegidas' + (marcadas >= tope ? ' · es el máximo' : ' · hasta ' + tope);

                        // Llegado al tope, las demás no se pueden marcar: es
                        // mejor que dejarlas marcar y fallar al enviar.
                        casillas.forEach(function (c) {
                            c.disabled = ! c.checked && marcadas >= tope;
                            c.closest('.con-casilla').classList.toggle('elegida', c.checked);
                        });
                    }

                    casillas.forEach(function (c) { c.addEventListener('change', pinta); });
                    pinta();
                })();
            </script>
        @endif

        @if ($eligiendoMaquina && $porArea->isEmpty())
            <p class="vacio">
                @if ($soloLibres)
                    Ahora mismo no hay nada libre aquí. Quita el filtro para verlo todo y
                    reservar más tarde.
                @elseif ($modo === 'autonomia')
                    Aquí no hay nada que puedas reservar por tu cuenta todavía.
                @elseif ($modo === 'herramientas')
                    Todavía no hay herramientas publicadas para prestar.
                @else
                    No hay equipos publicados en esta área.
                @endif
            </p>
        @endif
    @endif
</main>
@endsection
