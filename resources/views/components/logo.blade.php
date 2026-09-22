@php
    /**
     * La marca del laboratorio.
     *
     * Manda el logo subido en Comunicaciones → Marca; sin nada subido vale el
     * del archivo de configuración, que es el que trae el sistema. Estaba solo
     * en el archivo, así que cambiarlo exigía un despliegue: una marca se
     * retoca, y quien la tiene no es quien tiene acceso al servidor.
     *
     * Un SVG se inserta en línea para que herede el color del tema —un <img>
     * no lo haría y el logo saldría negro sobre fondo oscuro—. Cualquier otro
     * formato se muestra como imagen, que es lo que necesita el logo real del
     * Ean Fablab con sus propios colores.
     */
    $subido = \App\Support\Settings::logo();
    $disco = \Illuminate\Support\Facades\Storage::disk('public');

    if ($subido) {
        $archivo = $disco->path($subido);
        $url = $disco->url($subido);
        $esSvg = str_ends_with(strtolower($subido), '.svg');
    } else {
        $ruta = config('fabos.lab.logo');
        $archivo = $ruta ? public_path($ruta) : null;
        $url = $ruta ? asset($ruta) : null;
        $esSvg = $ruta && str_ends_with(strtolower($ruta), '.svg');
    }

    $existe = $archivo && is_file($archivo);
@endphp

@if ($existe && $esSvg)
    {!! file_get_contents($archivo) !!}
@elseif ($existe)
    <img src="{{ $url }}" alt="" aria-hidden="true">
@endif
