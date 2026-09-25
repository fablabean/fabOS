{{-- El color de la barra del menú (§3).

     Una marca no es sólo el logo: es el logo sobre algo. Con la barra fija en
     el crema del tema, un logo blanco no se podía usar —desaparecía— y quien
     tiene la marca quedaba atado a las versiones oscuras del logo.

     Se elige el fondo y nada más; lo que se escribe encima sale de él. Es lo
     que evita el fallo clásico: un fondo oscuro elegido con gusto y, encima,
     los enlaces grises de siempre, ilegibles. El resultado sería una barra de
     adorno que nadie puede usar.

     Sin color elegido no se pinta nada y manda el tema, que es lo que había y
     lo único que sabe responder al modo oscuro del sistema. --}}
@php $barra = \App\Support\Settings::colorDeLaBarra(); @endphp

@if ($barra)
    <style>
        /* Las dos barras del sitio: la pública y la de dentro. */
        .nav, header.top{
            --barra-fondo:{{ $barra['fondo'] }};
            @if ($barra['oscuro'])
                --barra-ink:#F5F6F0;
                --barra-soft:#C9CCC0;
                --barra-acento:#5CC9B8;
                --barra-linea:rgba(255,255,255,.14);
            @else
                --barra-ink:#191A16;
                --barra-soft:#3D4038;
                --barra-acento:#0D6E63;
                --barra-linea:rgba(0,0,0,.12);
            @endif

            /* Sin transparencia ni desenfoque: con un color elegido a mano,
               dejar que se mezcle con lo que pasa por debajo lo convierte en
               otro color según por dónde vaya la página. */
            background:var(--barra-fondo);
            backdrop-filter:none;
            border-bottom-color:var(--barra-linea);
        }

        .nav .marca-sitio, header.top .brand{color:var(--barra-ink)}
        .nav nav a, header.top nav a{color:var(--barra-soft)}
        .nav nav a:hover, header.top nav a:hover{color:var(--barra-acento)}
        .nav .marca-sitio em, header.top .brand em{color:var(--barra-acento)}

        /* El botón conserva su color de marca: es la llamada a la acción y no
           debe diluirse en el fondo que se haya elegido. */
        .nav nav a.btn, header.top nav a.btn{color:#fff}
        .nav nav a.btn:hover, header.top nav a.btn:hover{color:#fff}

        /* El saldo vive en la barra: su texto es el de la barra, no el de la
           página. El relleno se queda en el acento, que es lo que lo hace
           reconocible como botón. */
        .saldo-boton{color:var(--barra-ink);border-color:color-mix(in srgb,var(--barra-acento) 45%,transparent)}
        .saldo-boton span{color:var(--barra-soft)}

        /* Bajo 52 rem el menú se pliega y cuelga de la barra. Hereda su color:
           si no, se abre en crema debajo de una barra oscura. Y el botón de
           abrirlo son tres rayas del color del texto, que sobre fondo oscuro
           desaparecían. */
        @media (max-width:52rem){
            .menu-boton{color:var(--barra-ink);border-color:var(--barra-linea)}
            .menu-enlaces{background:var(--barra-fondo);border-bottom-color:var(--barra-linea)}
            .menu-enlaces > *{border-bottom-color:var(--barra-linea)}
        }
    </style>
@endif
