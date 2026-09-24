@extends('layouts.publico')
@section('title', 'Formación · ' . config('fabos.lab.name'))

@section('styles')
    .curso{
        background:var(--surface);border:1px solid var(--rule);border-radius:8px;
        padding:1.6rem;margin-bottom:1.2rem;
    }
    .curso h2{margin:0 0 .2rem;font-size:1.25rem}
    /* La foto, de banner: de lado a lado del bloque y entera.
       A media tarjeta y recortada competía con el texto en vez de
       presentarlo, y estiraba la etiqueta de nivel al ancho de su columna.
       Sin alto fijo ni `cover`: el ancho lo pone la tarjeta y el alto lo pone
       la imagen, que es lo que respeta un banner ya compuesto. */
    .curso.con-foto{overflow:hidden;padding-top:0}
    .curso .foto{display:block;width:calc(100% + 3.2rem);height:auto;
                 margin:0 -1.6rem 1.3rem;}
    .nivel{
        display:inline-block;font-size:.68rem;letter-spacing:.14em;text-transform:uppercase;
        font-weight:700;padding:.25rem .55rem;border-radius:4px;
        background:color-mix(in srgb,var(--ink) 8%,transparent);color:var(--muted);
        margin-bottom:.6rem;
    }
    .habilita{font-size:.85rem;color:var(--muted);margin:.6rem 0 0}
    .edicion{
        display:flex;flex-wrap:wrap;gap:.8rem;align-items:center;justify-content:space-between;
        border-top:1px solid var(--rule);padding:.9rem 0 0;margin-top:.9rem;
    }
    .edicion .cuando{font-size:.92rem}
    .edicion .cupo{font-size:.82rem;color:var(--muted)}
    .lleno{color:var(--muted);font-size:.85rem}
    /* El botón secundario: mismo peso que el principal, sin competirle. */
    .btn.suave{background:transparent;color:var(--accent);border:1px solid var(--accent)}
    .btn.suave:hover{background:color-mix(in srgb,var(--accent) 10%,transparent);filter:none}
    .mas{margin:1rem 0 0}
    .aviso{
        background:color-mix(in srgb,#0D6E63 12%,transparent);border-radius:6px;
        padding:.8rem 1rem;margin-bottom:1.4rem;font-size:.92rem;
    }
    .error{
        background:color-mix(in srgb,#9B2C2C 12%,transparent);border-radius:6px;
        padding:.8rem 1rem;margin-bottom:1.4rem;font-size:.92rem;
    }
@endsection

@section('content')
<main>
    <section>
        <p class="rotulo">Formación</p>
        <h1>Cursos del {{ config('fabos.lab.name') }}</h1>
        <p style="max-width:44rem;color:var(--muted)">
            La escalera va de <strong>bit</strong> —primer contacto— a <strong>tera</strong>,
            que es Fab Academy. Aprobar un curso no solo deja un certificado: habilita las
            máquinas que enseña, y desde ese momento se pueden reservar.
        </p>

        @if (session('status'))
            <div class="aviso">{{ session('status') }}</div>
        @endif

        @error('inscripcion')
            <div class="error">{{ $message }}</div>
        @enderror

        @forelse ($cursos as $curso)
            <article class="curso {{ $curso->photo_path ? 'con-foto' : '' }}">
                {{-- La foto del curso, de banner: se sube desde su ficha y
                     hasta ahora no se pintaba en ninguna parte. Un curso se
                     elige también por lo que se ve que se hace en él. --}}
                @if ($curso->photo_path)
                    <img class="foto" src="{{ asset('storage/' . $curso->photo_path) }}"
                         alt="{{ $curso->name }}" loading="lazy">
                @endif

                <span class="nivel">{{ $curso->level }}</span>
                <h2>{{ $curso->name }}</h2>

                @if ($curso->area)
                    <p style="margin:0;color:var(--muted);font-size:.88rem">{{ $curso->area->name }}</p>
                @endif

                @if ($curso->summary)
                    <p style="margin:.7rem 0 0">{{ $curso->summary }}</p>
                @endif

                @if ($curso->requirements)
                    <p class="habilita"><strong>Para entrar:</strong> {{ $curso->requirements }}</p>
                @endif

                @if ($curso->riskFamilies->isNotEmpty())
                    <p class="habilita">
                        <strong>Habilita:</strong> {{ $curso->riskFamilies->pluck('name')->implode(', ') }}
                    </p>
                @endif

                @if ($curso->hours || $curso->price_minor)
                    <p class="habilita">
                        @if ($curso->hours) {{ $curso->hours }} horas @endif
                        @if ($curso->hours && $curso->price_minor) · @endif
                        @if ($curso->price_minor)
                            {{ number_format($curso->precio(), 2, ',', '.') }} {{ config('fabos.currency.code') }}
                            {{-- Y en dólares, si el curso lo pide: Fab Academy
                                 tiene un precio en dólares y lo mira gente de
                                 fuera. Con la TRM del día, así que la cifra no
                                 envejece sola como lo haría escrita a mano. --}}
                            @if ($curso->mostrar_usd)
                                · {{ \App\Support\Dinero::enTexto((float) $curso->price_minor, 'usd') }}
                            @endif
                        @endif
                    </p>
                @endif

                {{-- El curso que tiene página propia la enseña siempre, haya o no
                     fechas: la tarjeta resume, y un programa de seis meses no se
                     decide con un resumen. --}}
                @if ($curso->by_preenrollment)
                    <p class="mas">
                        <a class="btn suave" href="{{ route('preinscripcion', $curso) }}">Ver más información</a>
                    </p>
                @endif

                @forelse ($curso->edicionesAbiertas as $edicion)
                    <div class="edicion">
                        <div>
                            <div class="cuando">
                                {{-- Una edición a tu ritmo no tiene fecha, y «Empieza
                                     el ·» a secas se leía como un dato que faltó. --}}
                                @if ($edicion->starts_on)
                                    Empieza el {{ $edicion->starts_on->format('d/m/Y') }}
                                    @if ($edicion->schedule_note) · {{ $edicion->schedule_note }} @endif
                                @else
                                    {{ $edicion->schedule_note ?: 'Empiezas cuando quieras' }}
                                @endif
                            </div>
                            <div class="cupo">
                                {{ $edicion->cuposLibres() }} de {{ $edicion->capacity }} cupos libres
                                @if ($edicion->instructor) · con {{ $edicion->instructor->name }} @endif
                            </div>
                        </div>

                        @auth
                            @if (isset($misInscripciones[$edicion->id]))
                                <span class="lleno">
                                    {{ $misInscripciones[$edicion->id] === 'aprobado'
                                        ? 'Ya lo aprobaste'
                                        : 'Ya estás inscrito' }}
                                </span>
                            @elseif ($edicion->cuposLibres() > 0)
                                <form method="POST" action="{{ route('formacion.inscribir', $edicion) }}">
                                    @csrf
                                    <button type="submit" class="btn">Inscribirme</button>
                                </form>
                            @else
                                <span class="lleno">Sin cupos</span>
                            @endif
                        @else
                            {{-- Un enlace con cara de botón, y no un <button> dentro de
                                 un <a>: eso no es HTML válido y además salía sin
                                 estilo, porque el sitio solo viste la clase .btn. --}}
                            <a class="btn" href="{{ route('login') }}">Entrar para inscribirme</a>
                        @endauth
                    </div>
                @empty
                    @if ($curso->by_preenrollment)
                        {{-- A este curso no se entra eligiendo una fecha: primero
                             hay que saber si hay cohorte. La puerta es su página,
                             que es donde se cuenta cuántos somos. --}}
                        @php($cohorte = $curso->cohortePorAbrir())
                        <div class="edicion">
                            <div>
                                <div class="cuando">
                                    @if ($cohorte?->starts_on)
                                        Cohorte {{ $cohorte->code }}, prevista para
                                        {{ $cohorte->starts_on->locale('es')->isoFormat('MMMM [de] YYYY') }}
                                    @else
                                        Se abre cuando se junta gente suficiente
                                    @endif
                                </div>
                                <div class="cupo">
                                    @if ($cohorte)
                                        {{ $cohorte->preinscritos() }}
                                        {{ $cohorte->preinscritos() === 1 ? 'persona preinscrita' : 'personas preinscritas' }}
                                        @if ($cohorte->minimum_to_open) de {{ $cohorte->minimum_to_open }} necesarias para abrir @endif
                                    @else
                                        Todavía no hay cohorte anunciada
                                    @endif
                                </div>
                            </div>

                            {{-- Sin cohorte no hay a qué preinscribirse, y «ver más
                                 información» ya está arriba: dos botones al mismo
                                 sitio solo hacen dudar cuál es cuál. --}}
                            @if ($cohorte)
                                <a class="btn" href="{{ route('preinscripcion', $curso) }}#preinscripcion">Preinscribirme</a>
                            @endif
                        </div>
                    @else
                        <p class="habilita">
                            Sin fechas abiertas por ahora. Escríbenos y te avisamos cuando se programe.
                        </p>
                    @endif
                @endforelse
            </article>
        @empty
            <div class="curso">
                <p style="margin:0">Todavía no hay cursos publicados.</p>
            </div>
        @endforelse
    </section>
</main>
@endsection
