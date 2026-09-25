@php
    /**
     * La marca del laboratorio, en sus versiones.
     *
     * Manda lo subido en Comunicaciones → Marca; sin nada subido vale el del
     * archivo de configuración, que es el que trae el sistema. Estaba solo en
     * el archivo, así que cambiarlo exigía un despliegue: una marca se retoca,
     * y quien la tiene no es quien tiene acceso al servidor.
     *
     * Dos ejes, y cada uno resuelve un problema distinto:
     *
     *  · **Larga o compacta.** La horizontal —con el nombre dentro— sale donde
     *    hay sitio; el símbolo solo, en el móvil, donde la larga se encogería
     *    hasta no leerse.
     *  · **Clara u oscura.** Un logo está dibujado para un fondo. El mismo
     *    archivo sobre el contrario se pierde, y aclararlo con un filtro le
     *    quita los colores y lo deja gris.
     *
     * Se manda el **alto** y el ancho sale solo. Al revés no funciona: una
     * marca horizontal y una cuadrada no comparten ancho, y fijarlo aplastaba
     * una de las dos.
     *
     * Un SVG se inserta en línea para que herede el color del tema —un <img>
     * no lo haría—. Cualquier otro formato va como imagen.
     */
    $disco = \Illuminate\Support\Facades\Storage::disk('public');
    $alto = \App\Support\Settings::altoDeLaMarca();
    $modo = \App\Support\Settings::modoDeLaBarra();

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

    // Sin nada subido, el del archivo: vale para todos los huecos, que es lo
    // que pasaba antes de que hubiera varios.
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

    /*
     * Sin la variante oscura que toca, primero la otra oscura y sólo después
     * la clara. El orden importa: cuando falta la compacta oscura, una larga
     * oscura apretada en el móvil se ve mal pero se ve, y la compacta clara
     * sobre fondo oscuro no se ve en absoluto. Entre pasarlo mal y
     * desaparecer, pasarlo mal.
     */
    $largaOscuraSubida = $pintar(\App\Support\Settings::logoLargoOscuro());
    $compactaOscuraSubida = $pintar(\App\Support\Settings::logoOscuro());

    $largaOscura = $largaOscuraSubida ?? $compactaOscuraSubida ?? $larga;
    $compactaOscura = $compactaOscuraSubida ?? $largaOscuraSubida ?? $compacta;

    /*
     * Con la barra fijada a mano, el modo del sistema no pinta nada: ese color
     * es el mismo para todo el mundo. Se elige aquí y se manda una sola
     * pareja, en vez de dejar que el CSS decida por el modo de quien mira.
     */
    $dobleModo = $modo === 'auto';

    if ($modo === 'oscuro') {
        [$larga, $compacta] = [$largaOscura, $compactaOscura];
    }
@endphp

@if ($larga || $compacta)
    @once
        <style>
            /* El alto manda y el ancho sale de la proporción. Más específico
               que la regla de cada plantilla —que fija un cuadrado— a
               propósito: esas siguen valiendo para todo lo demás. */
            .marca-fabos{display:inline-flex;align-items:center;flex:none}
            .marca-fabos span svg,
            .marca-fabos span img{
                display:block;width:auto;height:var(--marca-alto);
                max-width:min(52vw,22rem);color:var(--accent);flex:none;
            }

            /* Nada se ve por defecto y cada caso enciende el suyo: con cuatro
               combinaciones, encender es más corto de leer que apagar las
               otras tres, y sobre todo no deja ninguna sin cubrir. */
            .marca-fabos span{display:none}
            .marca-fabos .larga.clara{display:flex}

            /* Bajo esta anchura la larga ya no cabe. */
            @media (max-width:640px){
                .marca-fabos .larga.clara{display:none}
                .marca-fabos .compacta.clara{display:flex}
            }
            @media (prefers-color-scheme:dark){
                .marca-fabos .larga.clara{display:none}
                .marca-fabos .larga.oscura{display:flex}
            }
            @media (prefers-color-scheme:dark) and (max-width:640px){
                .marca-fabos .larga.oscura{display:none}
                .marca-fabos .compacta.clara{display:none}
                .marca-fabos .compacta.oscura{display:flex}
            }
        </style>
    @endonce

    @php
        $hueco = function (array $pieza, string $clases) {
            $dentro = $pieza['svg']
                ? file_get_contents($pieza['archivo'])
                : '<img src="' . e($pieza['url']) . '" alt="" aria-hidden="true">';

            return '<span class="' . $clases . '">' . $dentro . '</span>';
        };
    @endphp

    <span class="marca-fabos" style="--marca-alto:{{ $alto }}px">
        {!! $hueco($larga, 'larga clara') !!}
        {!! $hueco($compacta, 'compacta clara') !!}
        @if ($dobleModo)
            {!! $hueco($largaOscura, 'larga oscura') !!}
            {!! $hueco($compactaOscura, 'compacta oscura') !!}
        @endif
    </span>
@endif
