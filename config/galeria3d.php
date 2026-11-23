<?php

/*
 * La Galería 3D: objetos hechos (o que se pueden hacer) en el laboratorio,
 * para girarlos en el navegador y verlos en realidad aumentada.
 *
 * Cada modelo es una entrada aquí más sus archivos en public/3d/modelos
 * —{slug}.glb, {slug}-ar.glb, {slug}.usdz y {slug}.webp—, que salen de
 * `python3 herramientas/galeria-3d/construir.py`. Si falta alguno de los
 * archivos, el modelo no se enseña: mejor no verlo que verlo roto.
 *
 * Los nombres de `acabados.*.materiales` son los nombres de los materiales
 * dentro del .glb: el visor busca por nombre y cambia solo lo que se dice
 * (color, metal, rugosidad, emision, atenuacion).
 *
 * `ar.escala_fija`: true en lo que tiene sentido ver a tamaño real (un
 * soporte para celular), para que en el teléfono no se pueda agrandar ni encoger.
 */

return [

    // La galería es solo de impresión 3D: es la única técnica que se ofrece
    // al subir un modelo desde el panel.
    'categorias' => [
        'impresion-3d' => 'Impresión 3D',
    ],

    // Técnicas que ya no se ofrecen. Se conservan sus nombres para que un
    // modelo antiguo con una de ellas siga mostrando bien su técnica.
    'categorias_retiradas' => [
        'corte-laser'  => 'Corte láser',
        'fresado-cnc'  => 'Fresado CNC',
        'electronica'  => 'Electrónica',
        'diseno'       => 'Diseño de producto',
    ],

    /*
     * La dirección pública con la que se arman los códigos QR de cada figura
     * (los que se imprimen y se ponen junto a la pieza). Tiene que ser fija:
     * el dominio del sitio, p. ej. https://fablab.universidadean.edu.co.
     * Si se deja vacía, se usa APP_URL cuando es https y, si no, la dirección
     * con la que se abrió la página (localhost o el enlace del túnel del celular),
     * que sirve para probar pero no para imprimir.
     */
    'url_publica' => env('GALERIA3D_URL_PUBLICA'),

    /*
     * En el computador de desarrollo, el túnel del celular: lo enciende el
     * botón «Encender el QR para el celular» de la ficha (App\Support\TunelCelular)
     * y cuenta su enlace en esta dirección. Si la página se abre como localhost
     * y el túnel está encendido, los QR usan su enlace público.
     * `tunel_celular`: otro cloudflared que ya esté corriendo (opcional).
     */
    'tunel_celular' => env('GALERIA3D_TUNEL_CELULAR'),
    'tunel_celular_propio' => env('GALERIA3D_TUNEL_CELULAR_PROPIO', 'http://127.0.0.1:20241'),
    // El QR usa el enlace del túnel solo cuando ya está publicado en internet.
    'tunel_comprobar_dns' => (bool) env('GALERIA3D_TUNEL_COMPROBAR_DNS', true),

    // Los botones para filtrar la colección, además de «Todas». Las demás
    // categorías siguen existiendo (en la ficha y en el panel), pero no tienen
    // botón. Para volver a mostrarlas, se agregan aquí sus claves.
    'filtros' => ['impresion-3d'],

    /*
     * La calculadora «Imprimir» de cada figura: con el volumen y la superficie
     * del modelo estima gramos, metros de filamento, tiempo y costo. Es una
     * aproximación (boquilla de 0,4 mm, capas de 0,2 mm, 3 perímetros); la
     * cifra exacta la da el laminador.
     *
     * `precio_gramo`: lo que cobra el laboratorio por gramo, en `moneda`.
     * Vacío (null) = la calculadora no muestra costo. Se puede fijar en el
     * .env sin tocar este archivo.
     */
    'impresion' => [
        'moneda' => env('GALERIA3D_MONEDA', 'COP'),
        'materiales' => [
            'pla'    => ['nombre' => 'PLA',    'tipo' => 'fdm',    'densidad' => 1.24, 'precio_gramo' => env('GALERIA3D_PRECIO_PLA')],
            'petg'   => ['nombre' => 'PETG',   'tipo' => 'fdm',    'densidad' => 1.27, 'precio_gramo' => env('GALERIA3D_PRECIO_PETG')],
            'abs'    => ['nombre' => 'ABS',    'tipo' => 'fdm',    'densidad' => 1.04, 'precio_gramo' => env('GALERIA3D_PRECIO_ABS')],
            'tpu'    => ['nombre' => 'TPU',    'tipo' => 'fdm',    'densidad' => 1.21, 'precio_gramo' => env('GALERIA3D_PRECIO_TPU')],
            'resina' => ['nombre' => 'Resina', 'tipo' => 'resina', 'densidad' => 1.10, 'precio_gramo' => env('GALERIA3D_PRECIO_RESINA')],
        ],
        // Velocidades de una impresora de escritorio, contando desplazamientos.
        'fdm' => ['mm3_por_segundo' => 4.0, 'capa_mm' => 0.2, 'segundos_por_capa' => 3, 'pared_mm' => 1.2, 'filamento_mm' => 1.75],
        'resina' => ['capa_mm' => 0.05, 'segundos_por_capa' => 8],
    ],

    'modelos' => [

        'cubo' => [
            'nombre'    => 'Cubo de calibración',
            'categoria' => 'impresion-3d',
            'tecnica'   => 'Impresión 3D FDM',
            'maquina'   => 'Impresora FDM, capas de 0,2 mm',
            'material'  => 'PLA verde azulado',
            'resumen'   => 'Un cubo de 30 mm, la primera pieza que se imprime para revisar que la impresora mide bien. Solo 12 triángulos: dos por cara.',
            'descripcion' => 'Al terminar se mide con el calibrador: cada lado debe dar 30,0 mm. Si da de más o de menos, se ajustan los pasos por milímetro de ese eje; si las esquinas salen redondeadas o con hilos, la temperatura o la retracción. Es la malla más simple posible: 12 triángulos, el mínimo para un cubo.',
            'ar'        => ['escala_fija' => true],
            'vista'     => ['alejar' => 1.45],   // compacto: sin esto se ve más cerca que las demás
            'acabados'  => [
                ['nombre' => 'PLA verde azulado', 'materiales' => []],
                ['nombre' => 'PLA naranja', 'materiales' => ['PLA verde azulado' => ['color' => '#f08a24']]],
                ['nombre' => 'PLA blanco', 'materiales' => ['PLA verde azulado' => ['color' => '#eeeeea']]],
            ],
        ],

        'engranajes' => [
            'nombre'    => 'Caja de engranajes planetaria',
            'categoria' => 'impresion-3d',
            'tecnica'   => 'Impresión 3D FDM',
            'maquina'   => 'Impresora FDM, boquilla de 0,4 mm y capas de 0,2 mm',
            'material'  => 'PLA en cuatro colores y eje de acero',
            'resumen'   => 'Un sol, tres planetas y una corona que engranan de verdad. Gira la manivela y mira cómo se reparte el movimiento.',
            'descripcion' => 'Sol de 12 dientes, tres planetas de 12 y corona de 36 (módulo 2,5 mm). Por cada cuatro vueltas de la manivela, el portasatélites da una: una reducción 4:1 que cabe en la palma de la mano. Se imprime sin soportes, con 0,15 mm de holgura entre dientes para que gire sin lijar.',
            'ar'        => ['escala_fija' => false],
            'acabados'  => [
                ['nombre' => 'Colores de taller', 'materiales' => []],
                ['nombre' => 'Prototipo blanco', 'materiales' => [
                    'PLA naranja' => ['color' => '#f1f1ec'], 'PLA verde azulado' => ['color' => '#e4e4de'],
                    'PLA gris grafito' => ['color' => '#cfcfc8'], 'PLA blanco' => ['color' => '#f7f7f3'],
                ]],
                ['nombre' => 'PETG negro', 'materiales' => [
                    'PLA naranja' => ['color' => '#2a2b2e'], 'PLA verde azulado' => ['color' => '#1f2023'],
                    'PLA gris grafito' => ['color' => '#141517'], 'PLA blanco' => ['color' => '#2d2e31'],
                ]],
            ],
        ],

        'jarron' => [
            'nombre'    => 'Jarrón paramétrico en espiral',
            'categoria' => 'impresion-3d',
            'tecnica'   => 'Impresión 3D en modo vaso',
            'maquina'   => 'Impresora FDM, una sola pared continua de 1,2 mm',
            'material'  => 'PLA seda (brillo metálico)',
            'resumen'   => 'Siete lóbulos que se retuercen al subir. Prueba los acabados: el mismo jarrón cambia por completo con el filamento.',
            'descripcion' => 'Diseñado con una ecuación: el radio depende de la altura y del ángulo, y la forma gira 160° de abajo arriba. Se imprime en «modo vaso», una sola pared en espiral sin costuras, en unas tres horas. El modelo original tenía más de 400.000 triángulos; optimizado tiene unos 20.000 y las capas las pone la textura.',
            'ar'        => ['escala_fija' => false],
            'acabados'  => [
                ['nombre' => 'Seda dorada', 'materiales' => []],
                ['nombre' => 'Seda cobre', 'materiales' => ['PLA seda' => ['color' => '#b9673d']]],
                ['nombre' => 'Seda plata', 'materiales' => ['PLA seda' => ['color' => '#c9ced6']]],
                ['nombre' => 'PLA mate turquesa', 'materiales' => ['PLA seda' => ['color' => '#1f9e94']]],
                ['nombre' => 'PLA mate negro', 'materiales' => ['PLA seda' => ['color' => '#262729']]],
            ],
        ],

        'trofeo' => [
            'nombre'    => 'Trofeo del reto maker',
            'categoria' => 'impresion-3d',
            'tecnica'   => 'Impresión 3D en resina (SLA)',
            'maquina'   => 'Impresora de resina por estereolitografía',
            'material'  => 'Resina transparente y base lacada',
            'resumen'   => 'Una estrella retorcida en resina transparente. En el visor la luz se refracta a través de ella como en el objeto real.',
            'descripcion' => 'La resina se cura capa a capa con luz ultravioleta y sale transparente después de lijarla y barnizarla. Las caras planas, sin suavizar, son a propósito: hacen que la luz rebote en cada faceta.',
            'ar'        => ['escala_fija' => false],
            'acabados'  => [
                ['nombre' => 'Resina azul hielo', 'materiales' => []],
                ['nombre' => 'Resina ámbar', 'materiales' => ['Resina transparente' => ['color' => '#f0b45c']]],
                ['nombre' => 'Resina esmeralda', 'materiales' => ['Resina transparente' => ['color' => '#63c48c']]],
            ],
        ],

        'maceta' => [
            'nombre'    => 'Maceta con suculenta',
            'categoria' => 'impresion-3d',
            'tecnica'   => 'Impresión 3D y esmaltado',
            'maquina'   => 'Impresora FDM de gran formato',
            'material'  => 'PLA con relleno cerámico y esmalte',
            'resumen'   => 'Una maceta hexagonal con acabado tipo gres. Las hojas de la suculenta siguen el ángulo áureo, como en la planta real.',
            'descripcion' => 'Las 34 hojas se generan con la filotaxis de la naturaleza: cada una gira 137,5° respecto a la anterior. La maceta lleva un esmalte transparente encima del color, que es lo que le da ese brillo con profundidad.',
            'ar'        => ['escala_fija' => false],
            'acabados'  => [
                ['nombre' => 'Gres crema', 'materiales' => []],
                ['nombre' => 'Terracota', 'materiales' => ['Esmalte tipo gres' => ['color' => '#d0825f']]],
                ['nombre' => 'Azul cobalto', 'materiales' => ['Esmalte tipo gres' => ['color' => '#4c72c4']]],
                ['nombre' => 'Negro mate', 'materiales' => ['Esmalte tipo gres' => ['color' => '#3a3a3c']]],
            ],
        ],

        'pez' => [
            'nombre'    => 'Pez articulado',
            'categoria' => 'impresion-3d',
            'tecnica'   => 'Impresión 3D FDM, ensamblado en la impresora',
            'maquina'   => 'Impresora FDM, boquilla de 0,4 mm y capas de 0,2 mm',
            'material'  => 'PLA arcoíris (cambia de color a lo largo del carrete)',
            'resumen'   => 'Once segmentos que salen de la impresora ya unidos y se mueven como si nadara. Dale play y míralo ondular.',
            'descripcion' => 'Es un diseño «print-in-place»: las bisagras se imprimen con 0,8 mm de separación, así que el pez sale móvil sin armar nada. El filamento arcoíris cambia de tono cada pocos metros, por eso la cabeza es roja y la cola morada. Mide 16 cm y tarda unos 50 minutos.',
            'ar'        => ['escala_fija' => false],
            'acabados'  => [
                ['nombre' => 'Arcoíris', 'materiales' => []],
                ['nombre' => 'PLA seda dorado', 'materiales' => ['PLA arcoíris' => ['color' => '#d9a93a']]],
                ['nombre' => 'Brilla en la oscuridad', 'materiales' => ['PLA arcoíris' => ['color' => '#cfe9b8']]],
            ],
        ],

        'tornillo' => [
            'nombre'    => 'Tornillo y tuerca M20',
            'categoria' => 'impresion-3d',
            'tecnica'   => 'Impresión 3D FDM',
            'maquina'   => 'Impresora FDM, capas de 0,12 mm para la rosca',
            'material'  => 'PETG gris y PLA naranja',
            'resumen'   => 'Una rosca métrica de verdad, paso 2,5 mm. La tuerca sube y baja enroscándose: descárgalos en STL e imprímelos.',
            'descripcion' => 'El filete tiene el perfil trapezoidal del sistema métrico y la tuerca lleva 0,25 mm de holgura, la justa para que enrosque recién salida de la impresora. Se imprimen de pie, la cabeza abajo, sin soportes. Son piezas cerradas: el STL se puede laminar tal cual.',
            'ar'        => ['escala_fija' => false],
            'acabados'  => [
                ['nombre' => 'Gris y naranja', 'materiales' => []],
                ['nombre' => 'Todo negro', 'materiales' => ['PETG gris' => ['color' => '#26272b'], 'PLA naranja' => ['color' => '#2f3034']]],
                ['nombre' => 'Seda cobre', 'materiales' => ['PETG gris' => ['color' => '#b8693f'], 'PLA naranja' => ['color' => '#c9ced6']]],
            ],
        ],

        'impresion' => [
            'nombre'    => 'Así se imprime una pieza',
            'categoria' => 'impresion-3d',
            'tecnica'   => 'Impresión 3D FDM',
            'maquina'   => 'Cabezal con boquilla de latón de 0,4 mm',
            'material'  => 'PLA azul, relleno en rejilla al 20 %',
            'resumen'   => 'Un cubo detenido a medio imprimir para ver lo que no se ve: la base maciza, las dos paredes y el relleno por dentro.',
            'descripcion' => 'Una pieza FDM no es maciza: lleva unas capas sólidas abajo y arriba, dos o tres paredes por fuera y por dentro un relleno que ahorra plástico y tiempo. Aquí el cubo de 40 mm va por el 40 % y el cabezal —boquilla, bloque calefactor y disipador— recorre el perímetro de la capa.',
            'ar'        => ['escala_fija' => false],
            'acabados'  => [
                ['nombre' => 'PLA azul', 'materiales' => []],
                ['nombre' => 'PLA rojo', 'materiales' => ['PLA azul' => ['color' => '#d6402f'], 'Relleno' => ['color' => '#bf3a2a'], 'Filamento' => ['color' => '#d6402f']]],
                ['nombre' => 'PETG transparente', 'materiales' => ['PLA azul' => ['color' => '#cfe6ee'], 'Relleno' => ['color' => '#b8d8e2'], 'Filamento' => ['color' => '#cfe6ee']]],
            ],
        ],

        'soporte-celular' => [
            'nombre'    => 'Soporte para celular',
            'categoria' => 'impresion-3d',
            'tecnica'   => 'Impresión 3D FDM, de costado',
            'maquina'   => 'Impresora FDM, boquilla de 0,4 mm',
            'material'  => 'PLA verde',
            'resumen'   => 'Una sola pieza, sin soportes, que sostiene el celular a 68°. Míralo en tu escritorio a tamaño real.',
            'descripcion' => 'Se imprime acostado sobre su costado: así las capas corren a lo largo del respaldo y no se parte por la base cuando apoyas el teléfono. El labio delantero lo frena y el respaldo inclinado deja ver la pantalla desde la silla. Mide 8 × 7 cm de base.',
            'ar'        => ['escala_fija' => true],
            'acabados'  => [
                ['nombre' => 'PLA verde', 'materiales' => []],
                ['nombre' => 'PLA blanco', 'materiales' => ['PLA verde' => ['color' => '#eeece6']]],
                ['nombre' => 'PLA madera', 'materiales' => ['PLA verde' => ['color' => '#a87b52']]],
            ],
        ],

        'ajedrez' => [
            'nombre'    => 'Piezas de ajedrez',
            'categoria' => 'impresion-3d',
            'tecnica'   => 'Impresión 3D FDM',
            'maquina'   => 'Impresora FDM, capas de 0,16 mm',
            'material'  => 'PLA marfil y PLA negro',
            'resumen'   => 'Rey, torre, alfil y peón sobre un tablero de 4 × 2. Cada pieza es un perfil que gira, como en un torno.',
            'descripcion' => 'Las piezas se diseñan dibujando media silueta y haciéndola girar alrededor del eje. La torre lleva cinco almenas y el rey su cruz, añadidas aparte. Se imprimen de pie sin soportes; el rey mide 7,5 cm.',
            'ar'        => ['escala_fija' => false],
            'acabados'  => [
                ['nombre' => 'Marfil y negro', 'materiales' => []],
                ['nombre' => 'Seda oro y plata', 'materiales' => ['PLA marfil' => ['color' => '#d9b45a'], 'PLA negro' => ['color' => '#c3c8d0']]],
                ['nombre' => 'Rojo y azul', 'materiales' => ['PLA marfil' => ['color' => '#d6402f'], 'PLA negro' => ['color' => '#2f5fd6']]],
            ],
        ],

        'litofania' => [
            'nombre'    => 'Litofanía de Bogotá',
            'categoria' => 'impresion-3d',
            'tecnica'   => 'Impresión 3D FDM de litofanía',
            'maquina'   => 'Impresora FDM, capas de 0,12 mm y relleno al 100 %',
            'material'  => 'PLA blanco y base de PLA gris con tira LED',
            'resumen'   => 'Una lámina curva de PLA blanco cuyo relieve, a contraluz, dibuja los cerros de Bogotá. Aquí en versión low poly: la forma, sin la imagen.',
            'descripcion' => 'La lámina mide de 0,8 a 3,2 mm de grosor: donde es más delgada pasa más luz y se ve clara; donde es gruesa, oscura. La imagen se convierte en grosor punto por punto. Se imprime de pie, con relleno total y capas finas para que no se noten las líneas.',
            'ar'        => ['escala_fija' => false],
            'acabados'  => [
                ['nombre' => 'Encendida', 'materiales' => []],
                ['nombre' => 'Apagada', 'materiales' => ['PLA blanco' => ['color' => '#bdbab2'], 'Tira LED' => ['color' => '#9a978f']]],
            ],
        ],

    ],

];
