@extends('layouts.app')
@section('title', $zona->nombre . ' · ' . config('fabos.lab.name'))
@section('description', \Illuminate\Support\Str::limit(trim(strip_tags($zona->descripcion ?? '')), 160))

@section('content')
    <style>
        .zona-foto{display:block;width:100%;max-height:20rem;object-fit:cover;border-radius:8px;margin:.4rem 0 1.2rem}
        .zona-desc{max-width:42rem;color:var(--ink-soft)}
        .zona-desc p{margin:0 0 .8rem}.zona-desc ul,.zona-desc ol{margin:0 0 .8rem;padding-left:1.3rem}
        .modulos{display:grid;grid-template-columns:repeat(auto-fill,minmax(16rem,1fr));gap:1rem;margin-top:1.4rem}
        .modulo{display:flex;flex-direction:column;border:1px solid var(--rule);border-radius:8px;overflow:hidden;
                background:var(--surface);color:inherit;text-decoration:none}
        .modulo:hover{border-color:var(--accent)}
        .modulo img{width:100%;aspect-ratio:16/9;object-fit:cover;display:block;background:var(--ground)}
        .modulo .sin-portada{aspect-ratio:16/9;background:linear-gradient(135deg,var(--accent),color-mix(in srgb,var(--accent) 40%,#000));}
        .modulo .cuerpo{padding:.8rem 1rem 1rem}
        .modulo h2{font-size:1.05rem;margin:0 0 .35rem}
        .modulo p{margin:0;font-size:.9rem;color:var(--ink-soft)}
        .etiquetas{display:flex;flex-wrap:wrap;gap:.35rem;margin-top:.6rem}
        .etiqueta{font-size:.72rem;padding:.1rem .45rem;border-radius:99px;border:1px solid var(--rule);color:var(--muted)}
        .etiqueta.oculto{border-color:var(--warn);color:var(--warn)}
    </style>

    <a class="volver" href="{{ route('zonas.lista') }}">← Zonas de conocimiento</a>

    <h1 style="margin-top:.6rem">{{ $zona->nombre }}</h1>

    @if ($equipo && ! $zona->publicada)
        <p class="help" style="color:var(--warn)">Esta zona no está publicada: solo la ve el equipo.</p>
    @endif

    @if ($zona->fotoUrl())
        <img class="zona-foto" src="{{ $zona->fotoUrl() }}" alt="">
    @endif

    @if ($zona->descripcion)
        <div class="zona-desc">{!! $zona->descripcionHtml() !!}</div>
    @endif

    @if ($modulos->isEmpty())
        <p class="help">Todavía no hay módulos en esta zona.</p>
    @else
        <div class="modulos">
            @foreach ($modulos as $m)
                <a class="modulo" href="{{ $m->url() }}">
                    @if ($m->portadaUrl())
                        <img src="{{ $m->portadaUrl() }}" alt="" loading="lazy">
                    @else
                        <div class="sin-portada"></div>
                    @endif
                    <div class="cuerpo">
                        <h2>{{ $m->titulo }}</h2>
                        @if ($m->resumen)<p>{{ $m->resumen }}</p>@endif
                        <div class="etiquetas">
                            @if ($m->destacado)<span class="etiqueta">Destacado</span>@endif
                            @if ($m->acceso === 'cuenta')<span class="etiqueta">Con tu cuenta</span>@endif
                            @if ($m->acceso === 'inscritos')<span class="etiqueta">Para inscritos{{ $m->curso ? ' en ' . $m->curso->name : '' }}</span>@endif
                            @if (! $m->publicado)<span class="etiqueta oculto">Sin publicar</span>@endif
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
@endsection
