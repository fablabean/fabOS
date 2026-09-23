@extends('layouts.publico')
@section('title', 'Alianzas · ' . config('fabos.lab.name'))
@section('description', 'Proyectos que ' . config('fabos.lab.name') . ' está construyendo con otros, y a los que puedes unirte.')

@section('styles')
    .alianzas{display:grid;gap:1.2rem;grid-template-columns:repeat(auto-fill,minmax(20rem,1fr))}
    .alianza{
        background:var(--surface);border:1px solid var(--rule);border-radius:8px;padding:1.5rem;
        display:flex;flex-direction:column;gap:.6rem;text-decoration:none;color:var(--ink);
    }
    .alianza:hover{border-color:var(--accent)}
    .alianza h2{margin:0;font-size:1.2rem}
    .alianza .busca{color:var(--ink-soft);font-size:.95rem;margin:0}
    .alianza .partes{font-size:.84rem;color:var(--muted);margin:auto 0 0}
    .alianza .pie{font-family:ui-monospace,Consolas,monospace;font-size:.68rem;letter-spacing:.14em;
                  text-transform:uppercase;color:var(--accent);margin:.4rem 0 0}
    .vacio{background:var(--surface);border:1px solid var(--rule);border-radius:8px;padding:1.6rem;max-width:44rem}
@endsection

@section('content')
<main>
    <section>
        <p class="rotulo">Alianzas</p>
        <h1>Lo que estamos construyendo con otros</h1>
        <p class="lead">
            No todo lo que llega al laboratorio es un encargo. A veces alguien trae una idea que
            vale la pena construir juntos: el laboratorio pone máquinas y horas, quien la trajo pone
            la idea y su trabajo, y otros —una empresa, un inversor, otra facultad— ponen lo suyo.
            Estas son las que hay en marcha. A las que están recibiendo aliados se les puede
            pedir entrar; las demás están aquí para que se vean.
        </p>

        @if ($alianzas->isEmpty())
            <div class="vacio">
                <p style="margin:0">
                    Ahora mismo no hay ninguna en el sitio. Si tienes una idea que valga la pena
                    construir juntos, <a href="{{ route('proyectos.solicitar') }}">cuéntanosla</a>: así
                    es como empiezan.
                </p>
            </div>
        @else
            <div class="alianzas">
                @foreach ($alianzas as $a)
                    <a class="alianza" href="{{ route('alianzas.show', $a) }}">
                        <h2>{{ $a->name }}</h2>
                        @if ($a->summary)
                            <p class="busca">{{ \Illuminate\Support\Str::limit($a->summary, 180) }}</p>
                        @endif
                        <p class="partes">
                            {{ $a->partners->count() }} {{ $a->partners->count() === 1 ? 'parte' : 'partes' }}:
                            {{ $a->partners->map(fn ($p) => $p->esElLaboratorio() ? $p->name : ($p->organization ?: $p->name))->unique()->implode(', ') }}
                            @if ($a->area) · {{ $a->area->name }} @endif
                        </p>
                        {{-- Decirlo aqui evita entrar a una ficha a buscar un
                             formulario que no esta. --}}
                        <p class="pie">
                            {{ $a->admiteAliados() ? ($a->alliance_pitch ? 'Busca aliados' : 'Abierta') : 'En marcha' }} →
                        </p>
                    </a>
                @endforeach
            </div>
        @endif
    </section>
</main>
@endsection
