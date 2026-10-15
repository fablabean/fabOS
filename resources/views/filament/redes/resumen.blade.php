<x-filament-panels::page>
    <div class="rs-nav">
        <button type="button" wire:click="mover(-1)" class="rs-boton" aria-label="Mes anterior">‹ Mes anterior</button>
        <button type="button" wire:click="mover(1)" class="rs-boton" aria-label="Mes siguiente">Mes siguiente ›</button>
    </div>

    <div class="rs-cifras">
        <div class="rs-cifra">
            <strong>{{ $publicadas->count() }}</strong>
            <span>{{ $publicadas->count() === 1 ? 'publicación' : 'publicaciones' }}</span>
        </div>
        @foreach ($porRed as $red => $n)
            <div class="rs-cifra"><strong>{{ $n }}</strong><span>en {{ $red }}</span></div>
        @endforeach
    </div>

    @if ($publicadas->isNotEmpty())
        <div class="rs-repartos">
            <div>
                <h3>Por tema</h3>
                @foreach ($porPilar as $nombre => $n)
                    <div class="rs-barra"><span>{{ $nombre }}</span><i style="--p: {{ round($n / $publicadas->count() * 100) }}%"></i><b>{{ $n }}</b></div>
                @endforeach
            </div>
            <div>
                <h3>Por formato</h3>
                @foreach ($porFormato as $nombre => $n)
                    <div class="rs-barra"><span>{{ $nombre }}</span><i style="--p: {{ round($n / $publicadas->count() * 100) }}%"></i><b>{{ $n }}</b></div>
                @endforeach
            </div>
        </div>
    @endif

    <section class="rs-caja">
        <h3>Lo que salió</h3>
        @forelse ($publicadas as $p)
            <div class="rs-fila">
                <span class="cuando">{{ $p->publicada_at->timezone($tz)->locale('es')->isoFormat('ddd D') }}</span>
                <span class="que">
                    <a href="{{ \App\Filament\Resources\Publicaciones\PublicacionResource::getUrl('edit', ['record' => $p]) }}">{{ $p->titulo }}</a>
                    <small>{{ $p->nombresDeLasRedes() }} · {{ \App\Models\Publicacion::FORMATOS[$p->formato] ?? '' }}{{ $p->responsable ? ' · ' . $p->responsable->name : '' }}</small>
                </span>
                @if ($p->enlace)
                    <a class="enlace" href="{{ $p->enlace }}" target="_blank" rel="noopener">Ver publicación ↗</a>
                @endif
            </div>
        @empty
            <p class="rs-vacio">Nada publicado este mes.</p>
        @endforelse
    </section>

    @if ($noSalieron->isNotEmpty())
        <section class="rs-caja">
            <h3>Estaba previsto y no salió</h3>
            @foreach ($noSalieron as $p)
                <div class="rs-fila">
                    <span class="cuando">{{ $p->programada_para->timezone($tz)->locale('es')->isoFormat('ddd D') }}</span>
                    <span class="que">
                        <a href="{{ \App\Filament\Resources\Publicaciones\PublicacionResource::getUrl('edit', ['record' => $p]) }}">{{ $p->titulo }}</a>
                        <small>{{ $p->nombreDelEstado() }}</small>
                    </span>
                </div>
            @endforeach
        </section>
    @endif

    <style>
        .rs-nav { display:flex; gap:.5rem; }
        .rs-boton { border:1px solid rgba(0,0,0,.12); border-radius:.45rem; padding:.3rem .75rem; font-size:.85rem; background:transparent; cursor:pointer; }
        .dark .rs-boton { border-color:rgba(255,255,255,.18); }
        .rs-cifras { display:flex; gap:.8rem; flex-wrap:wrap; }
        .rs-cifra, .rs-caja, .rs-repartos > div { background:#fff; border:1px solid rgba(0,0,0,.08); border-radius:.75rem; padding:.9rem 1.1rem; }
        .dark .rs-cifra, .dark .rs-caja, .dark .rs-repartos > div { background:rgba(255,255,255,.04); border-color:rgba(255,255,255,.1); }
        .rs-cifra strong { display:block; font-size:1.8rem; line-height:1; }
        .rs-cifra span { font-size:.8rem; color:#6b7280; }
        .rs-repartos { display:grid; grid-template-columns:repeat(auto-fit,minmax(16rem,1fr)); gap:.8rem; }
        .rs-repartos h3, .rs-caja h3 { font-weight:700; margin-bottom:.5rem; }
        .rs-barra { display:grid; grid-template-columns:9rem 1fr 2rem; align-items:center; gap:.5rem; font-size:.82rem; margin-bottom:.3rem; }
        .rs-barra i { height:.55rem; border-radius:999px; background:linear-gradient(90deg, var(--primary-500,#f59e0b) var(--p), rgba(0,0,0,.06) var(--p)); }
        .rs-barra b { text-align:right; }
        .rs-fila { display:grid; grid-template-columns:4.5rem 1fr auto; gap:.8rem; align-items:center; padding:.5rem 0; border-top:1px solid rgba(0,0,0,.06); font-size:.88rem; }
        .dark .rs-fila { border-color:rgba(255,255,255,.08); }
        .rs-fila .cuando { color:#6b7280; text-transform:capitalize; }
        .rs-fila .que a { font-weight:600; }
        .rs-fila .que small { display:block; color:#6b7280; font-size:.75rem; }
        .rs-fila .enlace { font-size:.8rem; color:var(--primary-600,#d97706); white-space:nowrap; }
        .rs-vacio { color:#6b7280; font-size:.85rem; }
        @media print { .rs-nav, .fi-header-actions, .fi-sidebar, .fi-topbar { display:none !important; } }
    </style>
</x-filament-panels::page>
