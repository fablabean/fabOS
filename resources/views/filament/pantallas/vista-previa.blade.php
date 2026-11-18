@php
    /** @var \App\Models\Iot\Pantalla $pantalla */
    $pantalla = $getRecord();
    $dibujo = app(\App\Services\Iot\DibujoDePantalla::class);
    $momento = isset($momento) ? $momento : null;
    $png = base64_encode($dibujo->png($pantalla, $momento));
    $despertar = $dibujo->despertarEn($pantalla, $momento);
    $mostrada = $pantalla->version_mostrada;
    $version = $dibujo->version($pantalla, $momento);
@endphp

<div style="display:flex;gap:1.25rem;flex-wrap:wrap;align-items:flex-start">
    {{-- Al doble de tamaño y sin suavizar: así se ve cada punto como en la tinta. --}}
    <img src="data:image/png;base64,{{ $png }}" alt="Vista previa de la pantalla del salón {{ $pantalla->salon }}"
         width="400" height="300"
         style="width:100%;max-width:600px;height:auto;image-rendering:pixelated;border:10px solid #2b2b2b;border-radius:10px;background:#fff">

    @unless ($momento)
        <div style="font-size:.85rem;line-height:1.6;max-width:22rem">
            <p><strong>Resolución:</strong> 400 × 300, blanco y negro.</p>
            <p><strong>¿La pantalla ya lo muestra?</strong>
                @if (! $pantalla->visto_at)
                    Todavía no se ha conectado.
                @elseif ($mostrada === $version)
                    Sí.
                @else
                    Todavía no: lo mostrará la próxima vez que se actualice (o al pulsar su botón).
                @endif
            </p>
            <p><strong>Próxima actualización sola:</strong> en {{ \Carbon\CarbonInterval::seconds($despertar)->cascade()->locale('es')->forHumans(['parts' => 2]) }}, cuando cambie lo que muestra.</p>
        </div>
    @endunless
</div>
