{{-- Lo que suena y lo que viene, con la hora aproximada (App\Services\Musica\Rocola::estado).
     Lo usan la página de la rocola y el Parlante. Clases propias y estilos
     en línea: tiene que verse igual dentro del sitio y dentro del panel. --}}
@php
    $tz = config('fabos.lab.timezone');
    $hora = fn ($c) => $c->copy()->timezone($tz)->format('H:i');
    $dur = fn (int $ms) => intdiv(intdiv($ms, 1000), 60) . ':' . str_pad((string) (intdiv($ms, 1000) % 60), 2, '0', STR_PAD_LEFT);
    $actual = $estado['actual'] ?? null;
    $siguientes = $estado['siguientes'] ?? [];
@endphp

<div class="rocola-estado">
    @if ($estado === null)
        <p style="margin:0;opacity:.75">No pudimos saber qué está sonando: el parlante puede estar apagado.</p>
    @else
        <p style="margin:0 0 .5rem;font-size:.75rem;letter-spacing:.12em;text-transform:uppercase;opacity:.7">Sonando ahora</p>
        @if ($actual)
            <div style="display:flex;gap:.8rem;align-items:center">
                @if ($actual['portada'])<img src="{{ $actual['portada'] }}" alt="" width="64" height="64" style="border-radius:6px;flex:none;object-fit:cover">@endif
                <div style="min-width:0">
                    <strong style="display:block;font-size:1.05rem;line-height:1.25">{{ $actual['titulo'] }}</strong>
                    <span style="opacity:.75">{{ $actual['artista'] }}</span>
                    <span style="display:block;font-size:.85rem;opacity:.7">
                        {{ $actual['sonando'] ? 'termina hacia las ' . $hora($actual['termina']) : 'en pausa' }}
                        @if ($actual['pidio']) · la pidió {{ $actual['pidio'] }} @endif
                    </span>
                </div>
            </div>
        @else
            <p style="margin:0;opacity:.75">Nada en este momento.</p>
        @endif

        @if ($siguientes !== [])
            <p style="margin:1.1rem 0 .4rem;font-size:.75rem;letter-spacing:.12em;text-transform:uppercase;opacity:.7">Lo que viene</p>
            @foreach ($siguientes as $s)
                <div style="display:flex;gap:.7rem;align-items:center;padding:.35rem 0;border-top:1px solid rgba(128,128,128,.2)">
                    <span style="font-variant-numeric:tabular-nums;font-weight:600;width:3.2rem;flex:none">~{{ $hora($s['empieza']) }}</span>
                    @if ($s['portada'])<img src="{{ $s['portada'] }}" alt="" width="36" height="36" style="border-radius:4px;flex:none;object-fit:cover">@endif
                    <span style="min-width:0;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                        <strong>{{ $s['titulo'] }}</strong> · {{ $s['artista'] }}
                    </span>
                    @if ($s['pidio'])
                        <span style="font-size:.8rem;white-space:nowrap;padding:.1rem .45rem;border-radius:999px;background:rgba(13,110,99,.14)">♪ {{ $s['pidio'] }}</span>
                    @endif
                    <span style="font-size:.8rem;opacity:.6;flex:none">{{ $dur($s['duracion_ms']) }}</span>
                </div>
            @endforeach
            <p style="margin:.5rem 0 0;font-size:.78rem;opacity:.6">Horas aproximadas: cambian si alguien salta una canción.</p>
        @endif
    @endif
</div>
