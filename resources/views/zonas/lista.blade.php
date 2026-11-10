@extends('layouts.app')
@section('title', 'Zonas de conocimiento · ' . config('fabos.lab.name'))

@section('content')
    <style>
        .zonas{display:grid;grid-template-columns:repeat(auto-fill,minmax(16rem,1fr));gap:1rem;margin-top:1.2rem}
        .zona{display:block;border:1px solid var(--rule);border-radius:8px;overflow:hidden;background:var(--surface);
              color:inherit;text-decoration:none}
        .zona:hover{border-color:var(--accent)}
        .zona img{width:100%;aspect-ratio:16/9;object-fit:cover;display:block}
        .zona .cuerpo{padding:.8rem 1rem 1rem}
        .zona h2{font-size:1.05rem;margin:0 0 .3rem}
        .zona p{margin:0;font-size:.88rem;color:var(--muted)}
    </style>

    <a class="volver" href="{{ route('publico.home') }}">← Volver al inicio</a>

    <h1 style="margin-top:.6rem">Zonas de conocimiento</h1>
    <p class="help">El material del laboratorio, por tema.</p>

    @if ($zonas->isEmpty())
        <p class="help">Todavía no hay zonas publicadas.</p>
    @else
        <div class="zonas">
            @foreach ($zonas as $z)
                <a class="zona" href="{{ $z->url() }}">
                    @if ($z->fotoUrl())<img src="{{ $z->fotoUrl() }}" alt="" loading="lazy">@endif
                    <div class="cuerpo">
                        <h2>{{ $z->nombre }}</h2>
                        <p>{{ $z->modulos_count }} {{ $z->modulos_count === 1 ? 'módulo' : 'módulos' }}{{ $z->publicada ? '' : ' · sin publicar' }}</p>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
@endsection
