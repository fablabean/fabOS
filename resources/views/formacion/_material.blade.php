{{-- La foto o el video que acompaña una pantalla o una pregunta. Se recibe
     `$material` ya resuelto por el modelo: tipo y dirección, o null. --}}
@if ($material)
    <figure class="material material-{{ $material['tipo'] }}">
        @if ($material['tipo'] === 'imagen')
            <img src="{{ $material['url'] }}" alt="" loading="lazy">
        @elseif ($material['tipo'] === 'video')
            <video src="{{ $material['url'] }}" controls playsinline preload="metadata"></video>
        @elseif ($material['tipo'] === 'embed')
            <div class="marco">
                <iframe src="{{ $material['url'] }}" title="Video" loading="lazy" allowfullscreen
                        allow="accelerometer; encrypted-media; picture-in-picture" referrerpolicy="strict-origin-when-cross-origin"></iframe>
            </div>
        @else
            <a href="{{ $material['url'] }}" target="_blank" rel="noopener">Ver el video ↗</a>
        @endif
    </figure>

    @once
        <style>
            .material{margin:0 0 1rem}
            .material img,.material video{display:block;max-width:100%;max-height:70vh;border-radius:6px;background:#000}
            .material img{background:transparent;margin:0 auto}
            .material video{width:100%}
            /* 16:9 sin saber el tamaño: el iframe llena la caja. */
            .material .marco{position:relative;padding-top:56.25%;border-radius:6px;overflow:hidden;background:#000}
            .material .marco iframe{position:absolute;inset:0;width:100%;height:100%;border:0}
        </style>
    @endonce
@endif
