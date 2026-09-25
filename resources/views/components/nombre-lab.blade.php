@php
    /**
     * El nombre del laboratorio, escrito como lo dibuja el logo (§3).
     *
     * FABLAB en fino y EAN en negra, que es como está trazada la marca. El
     * nombre guardado sigue siendo texto plano —«FABLAB EAN»— y esto es sólo
     * cómo se pinta: meter el estilo dentro del ajuste lo arrastraría al
     * asunto de un correo, al nombre de un archivo y al título de la pestaña,
     * donde no hay negrita que valga y lo único que llegaría es la basura que
     * la marcara.
     *
     * La regla es «la última palabra pesa». Sirve para FABLAB EAN y para
     * cualquier otro laboratorio de la red con la misma forma de nombre; uno
     * de una sola palabra se pinta entero y ya, sin partirlo por la mitad.
     */
    $nombre = trim((string) ($corto ?? false ? config('fabos.lab.short_name') : config('fabos.lab.name')));
    $corte = mb_strrpos($nombre, ' ');
@endphp

@once
    <style>
        .nombre-lab{white-space:nowrap}
        /* Hereda el peso de donde esté —un título ya viene en negra— y sólo
           aligera la primera parte. Así funciona igual en el pie, que es texto
           normal, y en un <h1>, sin dos reglas que mantener a la par. */
        .nombre-lab .fina{font-weight:300}
        .nombre-lab .gruesa{font-weight:800}
    </style>
@endonce

@if ($corte === false)
    <span class="nombre-lab"><span class="gruesa">{{ $nombre }}</span></span>
@else
    <span class="nombre-lab"><span class="fina">{{ mb_substr($nombre, 0, $corte) }}</span> <span class="gruesa">{{ mb_substr($nombre, $corte + 1) }}</span></span>
@endif
