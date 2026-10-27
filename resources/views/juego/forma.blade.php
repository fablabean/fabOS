{{-- La forma de un botón, en el color del texto: sobre su cuadro de color,
     o sola. La misma que dibujan las gafas (public/img/recorridos/formas). --}}
@php $tam ??= '2.4rem'; @endphp
<svg viewBox="0 0 100 100" width="{{ $tam }}" height="{{ $tam }}" fill="none" stroke="currentColor"
     stroke-width="9" stroke-linejoin="round" stroke-linecap="round" aria-hidden="true" style="flex:none">
    @switch ($forma)
        @case('triangulo')
            <polygon points="10,16 90,16 50,88"/>
            @break
        @case('cubo')
            <polygon points="50,6 89,28 89,72 50,94 11,72 11,28"/>
            <polyline points="11,28 50,50 89,28"/>
            <line x1="50" y1="50" x2="50" y2="94"/>
            @break
        @case('gota')
            <path d="M50 6 C66 6 68 26 63 52 L54 90 Q50 97 46 90 L37 52 C32 26 34 6 50 6 Z"/>
            @break
        @case('estrella')
            <polygon points="50,7 62,36 93,38 69,58 77,89 50,72 23,89 31,58 7,38 38,36"/>
            @break
    @endswitch
</svg>
