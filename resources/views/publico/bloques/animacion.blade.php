{{-- Una animación en HTML (un mecanismo, una comparación, una línea de
     tiempo). Va en un iframe aislado: el archivo trae su propio código, y con
     `sandbox` no lee la sesión de quien visita. La dirección del archivo
     también responde con `sandbox`, por si alguien la abre sola. --}}
@php
    // Publicada, una dirección fija; en borrador, firmada por unas horas: el
    // iframe aislado no lleva la sesión de quien la está revisando.
    $parametros = [$pagina->slug, basename((string) $datos['archivo'])];
    $src = $pagina->estaVisible()
        ? route('publico.pagina.animacion', $parametros)
        : \Illuminate\Support\Facades\URL::temporarySignedRoute('publico.pagina.animacion', now()->addHours(6), $parametros);
    $forma = in_array($datos['proporcion'] ?? null, ['16/9', '4/3', '1/1', '3/4'], true) ? $datos['proporcion'] : '16/9';
@endphp
<figure class="bloque medio animacion">
    @if (filled($datos['titulo'] ?? null))
        <h2>{{ $datos['titulo'] }}</h2>
    @endif

    <iframe src="{{ $src }}" title="{{ $datos['titulo'] ?? 'Animación' }}" loading="lazy"
            sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox"
            style="aspect-ratio:{{ $forma }}"></iframe>

    <figcaption>
        {{ $datos['pie'] ?? '' }}
        <a href="{{ $src }}" target="_blank" rel="noopener" class="animacion-abrir">Ver en pantalla completa ↗</a>
    </figcaption>
</figure>
