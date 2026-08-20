@extends('layouts.publico')
@section('title', 'Formación · ' . config('fabos.lab.name'))

@section('styles')
    .curso{
        background:var(--surface);border:1px solid var(--rule);border-radius:8px;
        padding:1.6rem;margin-bottom:1.2rem;
    }
    .curso h2{margin:0 0 .2rem;font-size:1.25rem}
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
            <article class="curso">
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
                        @endif
                    </p>
                @endif

                @forelse ($curso->edicionesAbiertas as $edicion)
                    <div class="edicion">
                        <div>
                            <div class="cuando">
                                Empieza el {{ $edicion->starts_on?->format('d/m/Y') }}
                                @if ($edicion->schedule_note) · {{ $edicion->schedule_note }} @endif
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
                                    <button type="submit">Inscribirme</button>
                                </form>
                            @else
                                <span class="lleno">Sin cupos</span>
                            @endif
                        @else
                            <a href="{{ route('login') }}"><button type="button">Entrar para inscribirme</button></a>
                        @endauth
                    </div>
                @empty
                    <p class="habilita">
                        Sin fechas abiertas por ahora. Escríbenos y te avisamos cuando se programe.
                    </p>
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
