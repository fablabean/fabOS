@php
    /**
     * La marca del laboratorio, en sus dos versiones.
     *
     * Manda lo subido en Comunicaciones → Marca; sin nada subido vale el del
     * archivo de configuración, que es el que trae el sistema. Estaba solo en
     * el archivo, así que cambiarlo exigía un despliegue: una marca se retoca,
     * y quien la tiene no es quien tiene acceso al servidor.
     *
     * Dos versiones porque una marca suele venir en dos. La **larga** —la
     * horizontal, con el nombre dentro— sale donde hay sitio; la **compacta**
     * —el símbolo solo— en el móvil, donde la larga se encogería hasta no
     * leerse. Con una sola había que elegir, y la elegida quedaba mal en la
     * mitad de los sitios.
     *
     * Se manda el **alto** y el ancho sale solo. Al revés no funciona: una
     * marca horizontal y una cuadrada no comparten ancho, y fijarlo aplastaba
     * una de las dos. Lo que iguala la barra es el alto.
     *
     * Un SVG se inserta en línea para que herede el color del tema —un <img>
     * no lo haría y el logo saldría negro sobre fondo oscuro—. Cualquier otro
     * formato va como imagen, que es lo que necesita un logo con sus propios
     * colores.
     */
    $disco = \Illuminate\Support\Facades\Storage::disk('public');
    $alto = \App\Support\Settings::altoDeLaMarca();

    $pintar = function (?string $ruta) use ($disco) {
        if ($ruta === null) {
            return null;
        }

        $archivo = $disco->path($ruta);

        return is_file($archivo)
            ? ['archivo' => $archivo, 'url' => $disco->url($ruta), 'svg' => str_ends_with(strtolower($ruta), '.svg')]
            : null;
    };

    $larga = $pintar(\App\Support\Settings::logoLargo());
    $compacta = $pintar(\App\Support\Settings::logo());

    // Sin nada subido, el del archivo: vale para los dos huecos, que es lo que
    // pasaba antes de que hubiera dos.
    if (! $larga && ! $compacta) {
        $ruta = (string) config('fabos.lab.logo');
        $archivo = $ruta !== '' ? public_path($ruta) : null;

        if ($archivo && is_file($archivo)) {
            $compacta = ['archivo' => $archivo, 'url' => asset($ruta), 'svg' => str_ends_with(strtolower($ruta), '.svg')];
        }
    }

    // Con una sola versión, esa sale siempre: media marca es peor que una
    // marca que no cambia de tamaño.
    $larga ??= $compacta;
    $compacta ??= $larga;
@endphp

@if ($larga || $compacta)
    @once
        <style>
            /* El alto manda y el ancho sale de la proporción. Más específico
               que la regla de cada plantilla —que fija un cuadrado— a
               propósito: esas siguen valiendo para todo lo demás. */
            .marca-fabos{display:inline-flex;align-items:center;flex:none}
            .marca-fabos span{display:flex}
            .marca-fabos span svg,
            .marca-fabos span img{
                display:block;width:auto;height:var(--marca-alto);
                max-width:min(52vw,22rem);color:var(--accent);flex:none;
            }
            /* La compacta aparece donde la larga ya no cabe. Si sólo hay una
               subida, las dos ranuras llevan la misma y esto no se nota. */
            .marca-fabos .compacta{display:none}
            @media (max-width:640px){
                .marca-fabos .larga{display:none}
                .marca-fabos .compacta{display:flex}
            }
        </style>
    @endonce

    <span class="marca-fabos" style="--marca-alto:{{ $alto }}px">
        <span class="larga">
            @if ($larga['svg'])
                {!! file_get_contents($larga['archivo']) !!}
            @else
                <img src="{{ $larga['url'] }}" alt="" aria-hidden="true">
            @endif
        </span>
        <span class="compacta">
            @if ($compacta['svg'])
                {!! file_get_contents($compacta['archivo']) !!}
            @else
                <img src="{{ $compacta['url'] }}" alt="" aria-hidden="true">
            @endif
        </span>
    </span>
@endif
