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
    .reservas-mias{display:grid;gap:.5rem;margin-bottom:2rem}
    .mia{display:flex;flex-wrap:wrap;gap:.2rem 1rem;align-items:baseline;
         padding:.7rem .9rem;border:1px solid var(--rule);border-radius:6px;
         background:var(--surface)}
    .mia b{font-size:.95rem}
    .mia span{font-size:.84rem;color:var(--muted);font-variant-numeric:tabular-nums}
@endsection

@section('content')
<main>
    @php
        $enlaceDeArea = fn (string $slug) => route('publico.reservas', array_filter([
            'modo' => $modo ?: null,
            'area' => $slug,
        ]));
    @endphp

    <section style="padding-bottom:1rem">
        <p class="rotulo">Reservas</p>
        <h1>¿Cómo quieres usar el laboratorio?</h1>
        <p class="lead">
            Hay tres maneras, y conviene elegir antes de mirar máquinas: cambia lo que
            necesitas y lo que tienes que hacer.
        </p>
    </section>

    {{-- Los tres caminos. Producción sale del catálogo: no es una máquina que
         se reserve, es un encargo que se propone. --}}
    <div class="caminos">
        <a class="camino {{ $modo === 'asesoria' ? 'puesto' : '' }}"
           href="{{ route('publico.reservas', ['modo' => 'asesoria']) }}">
            <b>Asesoría</b>
            <span>Alguien del laboratorio te acompaña en la máquina. No necesitas
                  certifab: es justo para cuando todavía no lo tienes.</span>
            <span class="pie">Reservas un acompañamiento</span>
        </a>

        <a class="camino" href="{{ route('proyectos.solicitar') }}">
            <b>Producción</b>
            <span>No operas tú: nos cuentas qué necesitas y lo fabricamos nosotros.
                  Te respondemos con una propuesta, con precio y plazo.</span>
            <span class="pie">Propones un proyecto</span>
        </a>

        <a class="camino {{ $modo === 'autonomia' ? 'puesto' : '' }}"
           href="{{ route('publico.reservas', ['modo' => 'autonomia']) }}">
            <b>Autonomía</b>
            <span>Reservas y operas por tu cuenta, en los equipos donde ya tienes
                  certifab.</span>
            <span class="pie">Reservas la máquina</span>
        </a>
    </div>

    {{-- Reservar un espacio: no es una máquina, es una sala. Estaba en la
         página vieja y es lo que se pide para trabajar en grupo o dar clase. --}}
    <p class="lead" style="margin:-1.4rem 0 2rem">
        ¿Vas a trabajar en grupo o dar una clase?
        <a href="{{ route('espacios.index') }}"><strong>Reserva un espacio</strong></a>
        y toma dentro las herramientas que necesites.
        @if ($franjaHoy)
            Hoy el laboratorio atiende de <strong>{{ substr($franjaHoy[0], 0, 5) }}</strong>
            a <strong>{{ substr($franjaHoy[1], 0, 5) }}</strong>.
        @else
            Hoy no hay personal en jornada, así que lo que requiere acompañamiento no se
            puede reservar.
        @endif
    </p>

    {{-- Lo que ya tiene pedido. Es lo primero que se mira al llegar: antes de
         reservar otra cosa, saber qué hay. --}}
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

    {{-- ------------------------------------------------------------ autonomía --}}
    @if ($modo === 'autonomia')
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

    @if ($modo === 'asesoria')
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

    {{-- --------------------------------------------------- elegir área, o la lista --}}
    {{-- Las áreas solo cuando ya se eligió el camino: preguntar «qué área»
         antes de saber si vas a que te acompañen o a reservar tú es el segundo
         paso antes del primero. El área no significa lo mismo en cada caso. --}}
    @if ($modo === '')
        {{-- Nada más. Los tres caminos de arriba son toda la pregunta. --}}
    @elseif ($area === '')
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
        <a class="volver" href="{{ route('publico.reservas', array_filter(['modo' => $modo ?: null])) }}">
            ← Todas las áreas
        </a>

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

        @foreach ($eligiendoMaquina ? $porArea : [] as $nombreArea => $equipos)
            <section id="{{ $equipos->first()->area?->slug }}">
                <p class="rotulo">{{ $nombreArea }} · {{ $equipos->count() }}</p>
                <div class="equipos">
                    @foreach ($equipos as $e)
                        {{-- En asesoría se va derecho a pedir el acompañamiento, si el
                             equipo tiene asesores declarados. En lo demás, a la ficha. --}}
                        {{-- A donde lleva cada máquina depende del camino:
                             en asesoría, a pedir el acompañamiento; en
                             autonomía, derecho a reservarla; sin camino
                             elegido, a su ficha. --}}
                        <a class="equipo"
                           href="{{ match (true) {
                                $modo === 'asesoria' && $e->advisors_count > 0 => route('asesoria.show', $e),
                                $modo === 'autonomia' => route('reservas.show', $e),
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
                                <span>{{ $e->riskFamily?->name }}</span>
                            </div>
                        </a>
                    @endforeach
                </div>
            </section>
        @endforeach

        @if ($eligiendoMaquina && $porArea->isEmpty())
            <p class="vacio">
                @if ($soloLibres)
                    Ahora mismo no hay nada libre aquí. Quita el filtro para verlo todo y
                    reservar más tarde.
                @elseif ($modo === 'autonomia')
                    Aquí no hay nada que puedas reservar por tu cuenta todavía.
                @else
                    No hay equipos publicados en esta área.
                @endif
            </p>
        @endif
    @endif
</main>
@endsection
