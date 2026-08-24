<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Laboratorio
    |--------------------------------------------------------------------------
    | fabOS esta pensado para poder desplegarse en otros Fab Labs (§3). Nada
    | especifico de la EAN va incrustado en el codigo: vive aqui.
    */
    'lab' => [
        'name'       => env('LAB_NAME', 'Ean Fablab'),
        'short_name' => env('LAB_SHORT_NAME', 'Ean Fablab'),

        // A quien pertenece y donde queda. Aparecen en la portada, en el pie y
        // en los documentos que salen del sistema. Otro laboratorio de la red
        // cambia estas tres lineas y el sitio deja de hablar de la EAN.
        'institution' => env('LAB_INSTITUTION', 'Universidad EAN'),
        'city'        => env('LAB_CITY', 'Bogotá, Colombia'),
        'tagline'     => env('LAB_TAGLINE', 'Laboratorio de fabricación digital'),

        // Red a la que pertenece. Vacio si no pertenece a ninguna.
        'network'     => env('LAB_NETWORK', 'Fab Foundation'),

        // Zona horaria de operacion. La app guarda todo en UTC y muestra en esta.
        'timezone'   => env('LAB_TIMEZONE', 'America/Bogota'),

        // Marca. Por defecto se usa el SVG de fabOS, que hereda el color del
        // tema. Para poner el logo del laboratorio basta reemplazar el archivo
        // o apuntar LAB_LOGO a otra ruta dentro de public/.
        'logo'       => env('LAB_LOGO', 'img/fabos-logo.svg'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Vitrina publica
    |--------------------------------------------------------------------------
    | Una cifra pequena resta en vez de sumar: «1 persona habilitada» comunica
    | lo contrario de lo que se quiere. El dato aparece solo cuando ya cuenta
    | una historia, y hasta entonces se calla.
    */
    'showcase' => [
        'min_personas' => (int) env('PUBLIC_MIN_PERSONAS', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Banner de la portada
    |--------------------------------------------------------------------------
    | Rota entre lo que el laboratorio hace y lo que el sistema resuelve. Vive
    | en configuracion —y no en la vista— para que cambiar un mensaje sea editar
    | una linea, y para que la ilustracion de cada lamina se pueda reemplazar
    | por una foto real del taller sin tocar codigo.
    |
    | `titulo` admite <em> para resaltar; nada mas: es texto de configuracion,
    | no una plantilla.
    */
    'hero' => [
        [
            // Sin rotulo: la primera lamina se presenta con la identidad del
            // laboratorio, que se lee de fabos.lab al dibujar la pagina.
            'rotulo' => null,
            'titulo' => 'Aquí se fabrica lo que <em>todavía no existe</em>',
            'texto'  => 'Impresión 3D, corte láser, fresado CNC, electrónica, taller, robótica y realidad virtual. Abierto a estudiantes, docentes y empresas.',
            'imagen' => 'img/hero/fabricacion.svg',
        ],
        [
            'rotulo' => 'Reservas',
            'titulo' => 'Cada máquina, <em>con su agenda</em>',
            'texto'  => 'Mira qué está libre ahora mismo, reserva desde el teléfono y registra tu llegada escaneando el QR de la máquina.',
            'imagen' => 'img/hero/reservas.svg',
        ],
        [
            'rotulo' => 'Formación',
            'titulo' => 'De <em>bit</em> a <em>tera</em>, hasta Fab Academy',
            'texto'  => 'Los cursos habilitan las máquinas que enseñan y dejan un certificado que cualquiera puede verificar. Somos el único laboratorio acreditado en Colombia.',
            'imagen' => 'img/hero/formacion.svg',
        ],
        [
            'rotulo' => 'Habilitaciones',
            'titulo' => 'Nadie usa una máquina <em>sin saber usarla</em>',
            'texto'  => 'Los certifabs dicen quién puede operar qué y en qué condiciones. Si aún no puedes, el sistema te dice exactamente qué te falta.',
            'imagen' => 'img/hero/comunidad.svg',
        ],
        [
            'rotulo' => 'Tienda y encargos',
            'titulo' => 'Insumos, y trabajos <em>hechos por el equipo</em>',
            'texto'  => 'Compra material al detal o encarga un trabajo: lo cotizamos antes de producir y te avisamos cuando esté listo.',
            'imagen' => 'img/hero/tienda.svg',
        ],
        [
            'rotulo' => 'Proyectos',
            'titulo' => 'De la idea <em>al acta de cierre</em>',
            'texto'  => 'Acompañamos proyectos de la Universidad y de empresas, con cronograma, equipo a cargo y todo lo acordado por escrito.',
            'imagen' => 'img/hero/proyectos.svg',
        ],
    ],

    'currency' => [
        'code' => env('LAB_CURRENCY_CODE', 'FBC'),
        'name' => env('LAB_CURRENCY_NAME', 'FabCoin'),
        // 1 FabCoin = 100 unidades menores. Todo el libro contable usa enteros.
        'minor_units' => 100,

        // Cuantos pesos vale un FabCoin. Es un SUPUESTO administrable, y hace
        // falta para dos cosas: convertir una recarga en dinero real a saldo, y
        // ponerle precio en la tienda a un insumo del que solo se conoce su
        // costo de compra. Sin una tasa, cada insumo habria que tarifarlo a mano.
        'peso_rate' => (int) env('LAB_FABCOIN_PESOS', 1000),

        // Margen sobre el costo con el que se vende un insumo en la tienda.
        // Cubre desperdicio, manejo y el hecho de que se vende al detal lo que
        // se compra al por mayor.
        'retail_margin' => (float) env('LAB_RETAIL_MARGIN', 0.30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dinero real
    |--------------------------------------------------------------------------
    | El presupuesto y las compras NO se manejan en FabCoins: se hablan con el
    | area de compras de la Universidad, en pesos. Se guardan en pesos enteros
    | porque en Colombia los centavos no se usan y arrastrarlos solo produce
    | totales que no cuadran con la orden de compra real.
    */
    'money' => [
        'code'    => env('LAB_MONEY_CODE', 'COP'),
        'symbol'  => env('LAB_MONEY_SYMBOL', '$'),
        // Impuesto sobre las compras, para estimar el total con el que compras
        // trabaja. Es un supuesto administrable, no una verdad del sistema.
        'tax_rate' => (float) env('LAB_TAX_RATE', 0.19),

        // Costo por hora de trabajo del equipo, para costear proyectos. NO es
        // el sueldo de nadie: es una tarifa de referencia del laboratorio, que
        // se congela en cada registro para que subirla no reescriba el costo de
        // los proyectos ya cerrados. Es un SUPUESTO pendiente de decision.
        'hourly_cost' => (int) env('LAB_HOURLY_COST', 45000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Identidad
    |--------------------------------------------------------------------------
    | El dominio de identidad NO es el dominio de envio: los usuarios se
    | identifican con su correo institucional aunque el correo salga por
    | del laboratorio (§5).
    */
    'identity' => [
        'institutional_domain' => env('INSTITUTIONAL_EMAIL_DOMAIN', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Codigo de un solo uso (OTP)
    |--------------------------------------------------------------------------
    */
    /*
    |--------------------------------------------------------------------------
    | Asesorias
    |--------------------------------------------------------------------------
    | La puerta para quien todavia no tiene el certifab (§10). La duracion es
    | corta a proposito: una asesoria ocupa a alguien del equipo, y bloques
    | largos vacian la agenda del laboratorio.
    */
    'asesorias' => [
        'minutos' => (int) env('ASESORIA_MINUTOS', 45),
        'dias_vista' => (int) env('ASESORIA_DIAS_VISTA', 7),
    ],

    'otp' => [
        // Redis, no el almacen por defecto: en produccion la cache va a la
        // base de datos, y un codigo en claro acabaria dentro del respaldo
        // diario. En pruebas se sustituye por 'array'.
        'captura_almacen' => env('OTP_CAPTURA_ALMACEN', 'redis'),

        'length'           => (int) env('OTP_LENGTH', 6),
        'ttl_minutes'      => (int) env('OTP_TTL_MINUTES', 10),
        'max_attempts'     => (int) env('OTP_MAX_ATTEMPTS', 5),
        'throttle_per_email' => (int) env('OTP_THROTTLE_PER_EMAIL', 3),
        'throttle_window'  => (int) env('OTP_THROTTLE_WINDOW_MINUTES', 15),
        'remember_days'    => (int) env('OTP_REMEMBER_DAYS', 30),
    ],


    /*
    |--------------------------------------------------------------------------
    | Qué se está construyendo
    |--------------------------------------------------------------------------
    | Se muestra en la portada pública. Vive en configuración y no en la vista
    | para que actualizarlo sea cambiar una línea, y para que no se quede
    | contando una historia vieja.
    |
    | estado: listo | curso | proximo
    */
    'roadmap' => [
        ['estado' => 'listo',   'nombre' => 'Ingreso sin contraseñas',   'detalle' => 'Código al correo o escaneando el carné digital de la Universidad.'],
        ['estado' => 'listo',   'nombre' => 'Catálogo de equipos',       'detalle' => 'Los 82 activos del laboratorio, con su área y sus condiciones de uso.'],
        ['estado' => 'listo',   'nombre' => 'Reservas',                  'detalle' => 'Agenda por equipo, con llegada y salida escaneando el QR de la máquina.'],
        ['estado' => 'listo',   'nombre' => 'Habilitaciones',            'detalle' => 'Los certifabs que abren cada equipo, verificables públicamente.'],
        ['estado' => 'listo',   'nombre' => 'Formación',                 'detalle' => 'Los cursos bit, byte, kilo, mega, giga y tera, hasta Fab Academy. Aprobar habilita las máquinas y deja un certificado verificable.'],
        ['estado' => 'listo',   'nombre' => 'Mantenimiento',             'detalle' => 'Planes preventivos que se vuelven órdenes solos, y órdenes correctivas con evidencia fotográfica.'],
        ['estado' => 'curso',   'nombre' => 'FabCoins',                  'detalle' => 'La moneda interna para reservar equipos, espacios y acompañamiento. Ya calcula y guarda lo que cuesta cada reserva, pero el cobro sigue apagado hasta que se fijen las tarifas: así, cuando se encienda, ya hay histórico con el que contrastar.'],
        ['estado' => 'listo',   'nombre' => 'Tienda',                    'detalle' => 'Venta de insumos y trabajos por encargo. Se pide desde el sitio, se cotiza, se produce y se entrega, descontando el material del inventario.'],
        ['estado' => 'listo',   'nombre' => 'Proyectos',                 'detalle' => 'Del primer correo con una idea hasta el acta de cierre, con tablero, cronograma y el costo real de lo que consumió.'],
    ],


    /*
    |--------------------------------------------------------------------------
    | Horas extras
    |--------------------------------------------------------------------------
    | Política del laboratorio (§5). El control es PREVENTIVO: se valida al
    | programar, no al cerrar el mes. Un informe que a fin de mes avisa que
    | alguien se pasó no evita nada.
    |
    | Los topes y los recargos deben confirmarse con Talento Humano: la
    | legislación laboral colombiana ha cambiado varias veces.
    */
    'overtime' => [
        'max_semana_minutos' => (int) env('EXTRAS_MAX_SEMANA', 12 * 60),
        'max_mes_minutos'    => (int) env('EXTRAS_MAX_MES', 48 * 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Check-in y check-out
    |--------------------------------------------------------------------------
    | La ventana de llegada no es cortesía: sin un límite, una reserva sin
    | presentarse bloquea el equipo toda la franja y nadie más lo aprovecha.
    */
    'checkin' => [
        // Minutos antes del inicio en que ya se puede llegar.
        'antes' => (int) env('CHECKIN_ANTES_MINUTOS', 15),
        // Tolerancia de retraso. Pasada, la reserva se marca como no presentado.
        'tolerancia' => (int) env('CHECKIN_TOLERANCIA_MINUTOS', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Carnet digital EAN
    |--------------------------------------------------------------------------
    | Se usa para VERIFICAR identidad (enrolamiento, revalidacion, check-in),
    | nunca para sostener la sesion: si el servicio se cae, se entra por OTP.
    | Endpoint no documentado -> desactivable por configuracion.
    */
    'carnet' => [
        'enabled'  => (bool) env('CARNET_EAN_ENABLED', false),
        'base_url' => env('CARNET_EAN_BASE_URL'),
        'timeout'  => (int) env('CARNET_EAN_TIMEOUT', 5),
    ],

];
