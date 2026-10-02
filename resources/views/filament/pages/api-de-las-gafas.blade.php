<x-filament-panels::page>
    {{-- El CSS de Filament viene compilado sin tipografía para texto largo:
         la documentación lleva la suya. --}}
    <style>
        .doc-api{max-width:52rem;font-size:.95rem;line-height:1.65}
        .doc-api h2{font-size:1.3rem;font-weight:700;margin:2rem 0 .6rem;padding-top:1rem;border-top:1px solid rgba(128,128,128,.25)}
        .doc-api h3{font-size:1.05rem;font-weight:700;margin:1.5rem 0 .4rem}
        .doc-api p{margin:.6rem 0}
        .doc-api ul,.doc-api ol{margin:.5rem 0 .8rem;padding-left:1.4rem}
        .doc-api ul{list-style:disc} .doc-api ol{list-style:decimal}
        .doc-api li{margin:.25rem 0}
        .doc-api a{color:var(--primary-600);text-decoration:underline}
        .doc-api code{font-family:ui-monospace,Consolas,monospace;font-size:.85em;padding:.1rem .35rem;border-radius:4px;background:rgba(128,128,128,.14)}
        .doc-api pre{margin:.7rem 0 1rem;padding:.9rem 1rem;border-radius:8px;overflow-x:auto;background:#16181d;color:#e6e6e6;font-size:.82rem;line-height:1.55}
        .doc-api pre code{background:none;padding:0;color:inherit;font-size:inherit}
        .doc-api table{border-collapse:collapse;width:100%;margin:.7rem 0 1rem;font-size:.88rem}
        .doc-api th,.doc-api td{border:1px solid rgba(128,128,128,.3);padding:.45rem .6rem;text-align:left;vertical-align:top}
        .doc-api th{background:rgba(128,128,128,.1);font-weight:600}
        .doc-api strong{font-weight:700}
        .doc-api .endpoint{padding:.7rem .9rem;border-radius:8px;border:1px solid rgba(128,128,128,.3)}
        .doc-api .metodo{font-family:ui-monospace,Consolas,monospace;font-weight:700;font-size:.8rem;padding:.15rem .5rem;border-radius:4px;color:#fff}
        .doc-api .metodo.get{background:#30A46C} .doc-api .metodo.post{background:#3E63DD}
        .doc-api .copiar{font-size:.8rem;padding:.25rem .7rem;border-radius:6px;border:1px solid rgba(128,128,128,.4);cursor:pointer}
        .doc-api .base{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;padding:.8rem 1rem;border-radius:8px;
                       border:1px solid rgba(128,128,128,.3);margin-bottom:1rem}
    </style>

    <div class="doc-api">
        <div class="base">
            <strong>Base de la API:</strong>
            <code>{{ $base }}</code>
        </div>

        <h2 style="border-top:0;padding-top:0;margin-top:1rem">Endpoints</h2>
        <div style="display:flex;flex-direction:column;gap:.6rem;margin-bottom:1.5rem">
            @foreach ($endpoints as [$metodo, $url, $conToken, $para, $cuerpo])
                <div x-data="{ copiado: false }" class="endpoint">
                    <div style="display:flex;flex-wrap:wrap;gap:.6rem;align-items:center">
                        <span class="metodo {{ strtolower($metodo) }}">{{ $metodo }}</span>
                        <code style="font-size:.9rem;word-break:break-all;flex:1;min-width:16rem">{{ $url }}</code>
                        <button type="button" class="copiar"
                                @click="navigator.clipboard.writeText(@js($url)); copiado = true; setTimeout(() => copiado = false, 1500)"
                                x-text="copiado ? 'Copiada ✓' : 'Copiar'"></button>
                    </div>
                    <div style="font-size:.85rem;margin-top:.35rem;opacity:.8">
                        {{ $para }} ·
                        {{ $conToken ? 'Con «Authorization: Bearer <token>»' : 'Sin token' }}
                        @if ($cuerpo) · cuerpo: <code>{{ json_encode($cuerpo) }}</code> @endif
                    </div>
                </div>
            @endforeach
        </div>

        {!! $html !!}
    </div>
</x-filament-panels::page>
