{{-- Una imagen sola, con su pie. --}}
@php($ancho = ($datos['ancho'] ?? 'normal') === 'completo' ? ' completo' : '')

<figure class="bloque medio{{ $ancho }}">
    <img src="{{ asset('storage/' . $datos['imagen']) }}"
         alt="{{ $datos['pie'] ?? '' }}"
         loading="lazy">

    @if (filled($datos['pie'] ?? null))
        <figcaption>{{ $datos['pie'] }}</figcaption>
    @endif
</figure>
