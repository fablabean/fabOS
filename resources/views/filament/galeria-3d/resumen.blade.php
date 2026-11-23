@php
    $m = $getRecord();
    $e = $m?->estadisticas ?? [];
    $f = $e['fuente'] ?? [];
    $o = $e['optimizado'] ?? [];
    $peso = fn ($b) => \App\Support\Galeria3d::peso($b);
    $med = $e['medidas'] ?? null;
@endphp
@if ($m)
    {{-- Estilos propios: el panel no trae las clases de utilidad que no usa él mismo. --}}
    <style>
        .g3dp-resumen{display:grid;grid-template-columns:9rem 1fr;gap:1.25rem;align-items:center}
        @media (max-width:40rem){.g3dp-resumen{grid-template-columns:1fr}}
        .g3dp-resumen img{width:9rem;height:9rem;object-fit:contain;border-radius:.75rem;background:rgba(127,127,127,.08)}
        .g3dp-cifras{display:grid;grid-template-columns:repeat(auto-fill,minmax(9rem,1fr));gap:.75rem 1.25rem;margin:0}
        .g3dp-cifras dt{font-size:.75rem;opacity:.65}
        .g3dp-cifras dd{margin:0;font-weight:600;font-variant-numeric:tabular-nums}
        .g3dp-cifras dd small{font-weight:400;opacity:.65}
        .g3dp-origen{font-size:.8rem;opacity:.7;margin:.75rem 0 0}
    </style>
    <x-filament::section>
        <div class="g3dp-resumen">
            <img src="{{ $m->url('poster') }}" alt="{{ $m->nombre }}">
            <div>
                <dl class="g3dp-cifras">
                    <div><dt>Triángulos</dt><dd>{{ number_format($o['triangulos'] ?? 0, 0, ',', '.') }}
                        @if (($f['triangulos'] ?? 0) > ($o['triangulos'] ?? 0))<small>de {{ number_format($f['triangulos'], 0, ',', '.') }}</small>@endif</dd></div>
                    <div><dt>Peso en la web</dt><dd>{{ $peso($o['bytes'] ?? null) }}
                        @if (! empty($f['bytes']))<small>de {{ $peso($f['bytes']) }}</small>@endif</dd></div>
                    <div><dt>Llamadas de dibujo</dt><dd>{{ $o['llamadas'] ?? '—' }}</dd></div>
                    <div><dt>Medidas</dt><dd>
                        @if ($med){{ collect([$med['ancho'], $med['fondo'], $med['alto']])->map(fn ($v) => number_format($v * 100, 1, ',', '.'))->implode(' × ') }} cm @else — @endif
                    </dd></div>
                    <div><dt>Android / iPhone</dt><dd>{{ $peso($o['bytesAR'] ?? null) }} <small>/</small> {{ $peso($o['bytesUSDZ'] ?? null) }}</dd></div>
                    <div><dt>Materiales</dt><dd>{{ count($m->nombresDeMateriales()) ?: ($o['materiales'] ?? '—') }}</dd></div>
                </dl>
                @if ($m->archivo_original)
                    <p class="g3dp-origen">
                        Convertido desde «{{ $m->archivo_original }}»
                        @if ($m->subidoPor) · subido por {{ $m->subidoPor->name }} @endif
                        · {{ $m->updated_at?->timezone(config('fabos.lab.timezone'))->format('d/m/Y H:i') }}
                    </p>
                @endif
            </div>
        </div>
    </x-filament::section>
@endif
