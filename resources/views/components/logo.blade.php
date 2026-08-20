@php
    /**
     * La marca del laboratorio.
     *
     * Un SVG se inserta en línea para que herede el color del tema —un <img>
     * no lo haría y el logo saldría negro sobre fondo oscuro—. Cualquier otro
     * formato se muestra como imagen, que es lo que se necesita el día que
     * pongan el logo real del Ean Fablab con sus propios colores.
     */
    $ruta = config('fabos.lab.logo');
    $archivo = $ruta ? public_path($ruta) : null;
    $esSvg = $ruta && str_ends_with(strtolower($ruta), '.svg');
    $existe = $archivo && is_file($archivo);
@endphp

@if ($existe && $esSvg)
    {!! file_get_contents($archivo) !!}
@elseif ($existe)
    <img src="{{ asset($ruta) }}" alt="" aria-hidden="true">
@endif
