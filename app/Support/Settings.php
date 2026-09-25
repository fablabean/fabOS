<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Claves de configuracion administrables desde el backoffice.
 *
 * La regla: el valor por defecto siempre es el SEGURO. Si la tabla esta vacia,
 * si la cache falla o si alguien borra la fila, el sistema queda cerrado, no
 * abierto. Un interruptor de acceso nunca debe fallar hacia el "si".
 */
final class Settings
{
    /** Ingreso por carnet digital habilitado (§5). */
    public const CARNET_LOGIN = 'auth.carnet_login_enabled';

    /**
     * Se conserva la constante solo para poder borrar el ajuste viejo.
     *
     * Crear cuentas desde el carne no debe hacerse: el servicio de la EAN solo
     * devuelve el nombre completo, asi que la cuenta naceria **sin correo** —
     * incapaz de recibir un codigo, un recordatorio o un aviso de reserva, y
     * sin forma de arreglarla salvo a mano. La casilla existia en la pantalla
     * de Accesos sin estar conectada a nada.
     */
    public const CARNET_ENROLLMENT = 'auth.carnet_enrollment_enabled';

    /** Ingreso por codigo al correo. */
    public const OTP_LOGIN = 'auth.otp_login_enabled';

    /** Cobrar de verdad en FabCoins (§12). */
    public const COBROS_ACTIVOS = 'cobros.activos';

    /**
     * Apagado por defecto: mientras la tarifa ancla no este decidida, cobrar con
     * numeros supuestos seria peor que no cobrar. Se enciende desde Finanzas.
     */
    public static function cobrosActivos(): bool
    {
        return (bool) Setting::get(self::COBROS_ACTIVOS, false);
    }

    /**
     * Cobrar en la tienda aunque las reservas sigan sin cobrar (§14).
     *
     * Son dos decisiones distintas. Cobrar una reserva depende de tarifas que
     * todavia se estan decidiendo; cobrar un filamento en la tienda es un
     * precio que ya esta puesto. Con un solo interruptor, encender la tienda
     * obligaba a encender las reservas, y la gente compraba «con FabCoins»
     * sin que se le descontara nada.
     */
    public const COBROS_TIENDA = 'cobros.tienda';

    /*
     * Prestar una herramienta no se cobra (§12).
     *
     * Las tarifas se pensaron para maquinas —una hora de laser, una de CNC— y
     * las herramientas heredaban la de su familia de riesgo, que se tarifo
     * junto a las maquinas: un multimetro acababa cobrando la tarifa base del
     * laboratorio, mas cara que una impresora 3D. Prestar un destornillador no
     * ocupa una maquina ni gasta nada; cobrarlo solo desanima a pedirlo.
     *
     * La excepcion se dice poniendole al equipo su **tarifa propia**: eso es
     * justo lo que significa «esta si cuesta» —las gafas de realidad virtual,
     * el robot—. Lo heredado no cuenta, o no habria forma de distinguir lo
     * decidido de lo que cayo por herencia.
     */
    public const PRESTAMO_GRATIS = 'cobros.prestamo_de_herramientas_gratis';

    public static function prestamoDeHerramientasGratis(): bool
    {
        return (bool) Setting::get(self::PRESTAMO_GRATIS, true);
    }

    /*
     * Y tampoco exige estar habilitado (§7).
     *
     * El certifab dice que alguien te vio operar una maquina. Un multimetro o
     * unas gafas de realidad virtual no son una maquina: se piden, se usan y
     * se devuelven, y exigir un curso para llevarse un taladro solo consigue
     * que nadie lo pida.
     *
     * La excepcion se marca en la ficha del equipo -«exige certifab»-, y no
     * por familia de riesgo: las familias estan mezcladas, y quitarla ahi
     * abriria tambien las maquinas fijas que comparten familia con una
     * herramienta.
     */
    public const PRESTAMO_SIN_CERTIFAB = 'reservas.prestamo_sin_certifab';

    public static function prestamoSinCertifab(): bool
    {
        return (bool) Setting::get(self::PRESTAMO_SIN_CERTIFAB, true);
    }

    /*
     * En que moneda se trabaja en el panel (§12).
     *
     * Todo importe se guarda en unidades menores de FabCoin, que es como lo
     * lleva el libro contable. Lo que decide esto es en que se escribe y en
     * que se lee mientras se administra.
     *
     * Hacia falta porque cada pantalla habia elegido la suya: un curso se
     * tarifaba en FabCoins, un servicio de la tienda en pesos y un insumo
     * tambien en pesos, sin que nadie lo hubiera decidido asi. Quien pasaba de
     * una a otra tenia que acordarse de en cual estaba.
     *
     * No toca el sitio publico: ahi la regla es otra y es buena —a quien entra
     * sin cuenta se le habla en pesos, a quien la tiene en FabCoins, porque el
     * saldo es suyo— y forzarla desde aqui le diria a alguien el precio en una
     * moneda que no es la de su saldo.
     */
    public const MONEDA_DE_TRABAJO = 'cobros.moneda_de_trabajo';

    /*
     * La ultima TRM que se pudo consultar, con su fecha.
     *
     * No es un ajuste que nadie escriba: lo guarda el servicio al conseguirla.
     * Existe para el dia que no haya red —la de ayer sirve mucho mejor que el
     * supuesto de la configuracion, que lleva ahi desde que se monto esto—.
     */
    public const TRM_ULTIMA = 'finanzas.trm_ultima';

    public static function monedaDeTrabajo(): string
    {
        return Setting::get(self::MONEDA_DE_TRABAJO, 'fbc') === 'pesos' ? 'pesos' : 'fbc';
    }

    /*
     * El beneficio semanal de FabCoins (§12): cada semana, a quien tenga
     * correo de una institucion aliada, el sistema le completa el saldo
     * hasta el tope. No se acumula: quien ya tiene el tope o mas, no recibe.
     */
    public const BENEFICIO_ACTIVO        = 'beneficio.activo';
    public const BENEFICIO_SEMANAL       = 'beneficio.semanal_minor';
    public const BENEFICIO_DOMINIOS      = 'beneficio.dominios';
    public const BENEFICIO_EQUIVALENCIAS = 'beneficio.equivalencias';

    /**
     * A cuánto material equivale el beneficio de la semana.
     *
     * Se dice, no se calcula: el equivalente exacto depende del relleno, del
     * calibre y de lo que se desperdicia en el corte, y una cifra falsa es
     * peor que una orientación honesta. Sale al cerrar una producción, para
     * que quien la cierra sepa hasta dónde llega.
     */
    public static function equivalenciasDelBeneficio(): string
    {
        return trim((string) Setting::get(self::BENEFICIO_EQUIVALENCIAS, ''))
            ?: '60 g de filamento, o 20 × 20 cm de MDF de 2,7 mm';
    }

    public const BENEFICIO_DOMINIOS_DE_FABRICA = ['universidadean.edu.co', 'fablabean.com', 'ieee.org'];

    public static function beneficioActivo(): bool
    {
        return (bool) Setting::get(self::BENEFICIO_ACTIVO, false);
    }

    /** El tope semanal, en unidades menores: 8 FabCoins de fabrica. */
    public static function beneficioSemanalMenor(): int
    {
        return max(0, (int) Setting::get(self::BENEFICIO_SEMANAL, 8 * (int) config('fabos.currency.minor_units', 100)));
    }

    /** @return list<string> dominios de correo con derecho al beneficio, en minusculas */
    public static function dominiosDelBeneficio(): array
    {
        $guardados = Setting::get(self::BENEFICIO_DOMINIOS);

        if (is_string($guardados)) {
            $guardados = preg_split('/[\s,;]+/', $guardados) ?: [];
        }

        $lista = collect(is_array($guardados) ? $guardados : self::BENEFICIO_DOMINIOS_DE_FABRICA)
            ->map(fn ($d) => strtolower(trim(ltrim((string) $d, '@'))))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $lista ?: self::BENEFICIO_DOMINIOS_DE_FABRICA;
    }

    /*
     * Los pagos por QR (§11): el codigo del banco es uno solo para todo el
     * laboratorio, y se sube desde Finanzas → Pagos. Va con el valor en cada
     * correo de cobro y se ve en la pagina del proyecto.
     */
    public const PAGOS_QR            = 'pagos.qr_path';
    public const PAGOS_INSTRUCCIONES = 'pagos.instrucciones';

    /** La ruta del QR en el disco privado, si esta subido. */
    public static function qrDePagos(): ?string
    {
        $ruta = trim((string) Setting::get(self::PAGOS_QR, ''));

        return $ruta !== '' && \Illuminate\Support\Facades\Storage::disk('local')->exists($ruta) ? $ruta : null;
    }

    public static function instruccionesDePago(): string
    {
        return trim((string) Setting::get(self::PAGOS_INSTRUCCIONES, ''))
            ?: 'Escanea el código QR con la app de tu banco, paga el valor indicado y respóndenos aquí con el comprobante.';
    }

    /**
     * Lo que cuesta una asesoria, en unidades menores (§12).
     *
     * Un precio plano —2 FabCoins de fabrica— y no una tarifa por hora: la
     * asesoria dura lo que dura, y lo que se paga es el tiempo de alguien del
     * equipo, no el de una maquina. Cero: gratis. Se edita en Finanzas → Cobros.
     */
    public const ASESORIA_PRECIO = 'asesorias.precio_minor';

    public static function precioDeAsesoriaMenor(): int
    {
        return max(0, (int) Setting::get(self::ASESORIA_PRECIO, 2 * config('fabos.currency.minor_units', 100)));
    }

    /**
     * Cuantas herramientas sueltas se pueden pedir en una sola reserva (§7).
     *
     * Un tope, porque sin el alguien se lleva el taller entero en una tarde
     * «por si acaso». Es un numero que decide la coordinacion, no el codigo:
     * se edita en Operacion → Prestamo de herramientas.
     */
    public const HERRAMIENTAS_POR_RESERVA = 'reservas.max_herramientas';

    public static function maxHerramientasPorReserva(): int
    {
        return max(1, (int) Setting::get(self::HERRAMIENTAS_POR_RESERVA, 5));
    }

    /** La base del acuerdo de servicio que redacta el sistema (§11). */
    public const ACUERDO_CLAUSULAS  = 'proyectos.acuerdo_clausulas';
    public const ACUERDO_FORMA_PAGO = 'proyectos.acuerdo_forma_pago';

    /*
     * El banner de la guia de reservas (§10): una foto que ayude a reconocer
     * el camino sin preguntar —un mapa de los cuatro, una infografia—, y un
     * texto corto encima. Se editan en Comunicaciones → Guia de reservas.
     */
    public const GUIA_IMAGEN = 'reservas.guia_imagen';
    public const GUIA_TEXTO  = 'reservas.guia_texto';

    /** La ruta de la imagen en el disco publico, si esta subida. */
    public static function imagenDeLaGuia(): ?string
    {
        $ruta = trim((string) Setting::get(self::GUIA_IMAGEN, ''));

        return $ruta !== '' && \Illuminate\Support\Facades\Storage::disk('public')->exists($ruta) ? $ruta : null;
    }

    public static function textoDeLaGuia(): string
    {
        return trim((string) Setting::get(self::GUIA_TEXTO, ''));
    }

    /*
     * El logo del laboratorio (§3).
     *
     * Estaba en un archivo del repositorio —`config('fabos.lab.logo')`—, asi
     * que cambiarlo exigia un despliegue. Una marca se retoca: el dia que
     * llegue la version definitiva, o la del aniversario, tiene que poder
     * subirla quien la tiene, no quien tiene acceso al servidor. Se sube en
     * Comunicaciones → Marca. Sin nada subido, sigue valiendo el del archivo.
     */
    public const MARCA_LOGO = 'marca.logo_path';

    /**
     * Y la version larga, para donde hay sitio (§3).
     *
     * Una marca suele venir en dos: la horizontal, con el nombre dentro, y la
     * compacta, que es el simbolo solo. Con una sola casilla habia que elegir,
     * y la elegida quedaba mal en la mitad de los sitios: la larga aplastada
     * en el cuadrado del movil, o la compacta perdida en una cabecera ancha.
     *
     * La larga manda donde cabe —la barra en pantalla de trabajo, la cabecera
     * de un PDF— y la compacta en el movil y en la pestana del navegador, que
     * es un cuadrado de dieciseis pixeles.
     */
    public const MARCA_LOGO_LARGO = 'marca.logo_largo_path';

    /**
     * Las mismas dos, para fondo oscuro (§3).
     *
     * Un logo esta dibujado para un fondo. El mismo archivo sobre el contrario
     * se pierde: negro sobre negro no se ve, y una marca que se aclara con un
     * filtro pierde sus colores y queda gris. Quien tiene la marca casi
     * siempre tiene las dos versiones; lo unico que faltaba era donde ponerlas.
     *
     * Sin version oscura vale la clara, que es lo que habia.
     */
    public const MARCA_LOGO_LARGO_OSCURO = 'marca.logo_largo_oscuro_path';

    public const MARCA_LOGO_OSCURO = 'marca.logo_oscuro_path';

    /**
     * Y el icono de la pestana, que pide lo suyo (§3).
     *
     * La compacta servia de apano, pero un favicon no es un logo pequeno: se
     * ve a dieciseis pixeles, donde un trazo fino desaparece y dos colores
     * parecidos se funden en uno. Lo que ahi funciona suele ser otro dibujo
     * —una letra, una figura— y no la marca encogida.
     *
     * Sin nada aqui vale la compacta, y sin compacta la larga: es mejor un
     * icono apretado que ninguno.
     */
    public const MARCA_FAVICON = 'marca.favicon_path';

    /**
     * El alto de la marca en la barra, en pixeles.
     *
     * El ancho sale solo, de la proporcion de la imagen. Al reves no funciona:
     * una marca horizontal y una cuadrada no comparten ancho, y fijarlo
     * aplastaba una de las dos. Lo que se quiere igualar entre todas es el
     * alto, que es lo que hace que la barra se vea pareja.
     */
    public const MARCA_ALTO = 'marca.alto';

    /** Si el nombre del laboratorio acompana al logo, o el logo va solo. */
    public const MARCA_CON_TEXTO = 'marca.con_texto';

    /**
     * El color de la barra del menu (§3).
     *
     * Una marca no es solo el logo: es el logo sobre algo. Con la barra fija
     * en el crema del tema, un logo blanco no se podia usar —desaparecia— y
     * quien tiene la marca quedaba atado a las versiones oscuras.
     *
     * Se elige el fondo y nada mas. El color del texto sale de el, que es lo
     * que evita el fallo clasico: fondo oscuro elegido con gusto y enlaces
     * grises ilegibles encima, con la barra convertida en un adorno que nadie
     * puede usar.
     */
    public const MARCA_BARRA = 'marca.barra_color';

    /** Alto por defecto: lo que venia fijo en las plantillas (2,4 rem). */
    public const ALTO_POR_DEFECTO = 38;

    /** La ruta del logo compacto subido, en el disco publico. Nula si no hay. */
    public static function logo(): ?string
    {
        return self::rutaSubida(self::MARCA_LOGO);
    }

    /** La ruta de la version larga. Nula si no hay. */
    public static function logoLargo(): ?string
    {
        return self::rutaSubida(self::MARCA_LOGO_LARGO);
    }

    /** La ruta del icono de la pestana. Nula si no hay. */
    public static function favicon(): ?string
    {
        return self::rutaSubida(self::MARCA_FAVICON);
    }

    /** Las dos versiones para fondo oscuro. Nulas si no hay. */
    public static function logoLargoOscuro(): ?string
    {
        return self::rutaSubida(self::MARCA_LOGO_LARGO_OSCURO);
    }

    public static function logoOscuro(): ?string
    {
        return self::rutaSubida(self::MARCA_LOGO_OSCURO);
    }

    /**
     * Sobre que fondo se va a ver la marca en la barra.
     *
     * Aqui se cruzan dos cosas y conviene que se crucen bien. El sistema de
     * quien mira puede estar en modo oscuro, pero si el laboratorio fijo un
     * color para la barra, ese color es el mismo para todo el mundo y el modo
     * del sistema no lo cambia. Sin esto, una barra puesta en negro a mano
     * enseñaria el logo claro solo a quien tenga el movil en modo oscuro, y el
     * negro sobre negro al resto.
     *
     * - `auto`   · manda el sistema de quien mira, y se decide con CSS.
     * - `claro`  · barra clara fijada a mano: siempre la version clara.
     * - `oscuro` · barra oscura fijada a mano: siempre la oscura.
     */
    public static function modoDeLaBarra(): string
    {
        $barra = self::colorDeLaBarra();

        return match (true) {
            $barra === null => 'auto',
            $barra['oscuro'] => 'oscuro',
            default => 'claro',
        };
    }

    private static function rutaSubida(string $clave): ?string
    {
        $ruta = trim((string) Setting::get($clave, ''));

        return $ruta !== '' && \Illuminate\Support\Facades\Storage::disk('public')->exists($ruta) ? $ruta : null;
    }

    /** El alto de la marca en la barra, en pixeles. */
    public static function altoDeLaMarca(): int
    {
        $alto = (int) Setting::get(self::MARCA_ALTO, self::ALTO_POR_DEFECTO);

        // Entre algo que se vea y algo que no rompa la barra.
        return max(16, min(120, $alto ?: self::ALTO_POR_DEFECTO));
    }

    /** Si el nombre acompana al logo. Por defecto si: es lo que habia. */
    public static function marcaConTexto(): bool
    {
        return (bool) Setting::get(self::MARCA_CON_TEXTO, true);
    }

    /**
     * El color de la barra, con lo que hay que escribir encima.
     *
     * Nulo cuando no se ha elegido ninguno: entonces manda el tema, que es lo
     * que habia y lo unico que sabe responder al modo oscuro del sistema.
     *
     * @return array{fondo:string,oscuro:bool}|null
     */
    public static function colorDeLaBarra(): ?array
    {
        $color = strtoupper(trim((string) Setting::get(self::MARCA_BARRA, '')));

        if (! preg_match('/^#[0-9A-F]{6}$/', $color)) {
            return null;
        }

        return ['fondo' => $color, 'oscuro' => self::esOscuro($color)];
    }

    /**
     * Si sobre este color hay que escribir en claro.
     *
     * Luminancia relativa segun WCAG: el ojo no pesa igual los tres canales
     * —el verde mucho, el azul casi nada—, y un promedio simple da por claro
     * un azul intenso sobre el que no se lee nada en negro. El umbral 0,45
     * esta algo por debajo del medio a proposito: en la duda, texto claro, que
     * aguanta mejor los tonos medios.
     */
    private static function esOscuro(string $hex): bool
    {
        [$r, $g, $b] = array_map(
            fn (string $par) => hexdec($par) / 255,
            str_split(substr($hex, 1), 2),
        );

        $lineal = fn (float $c) => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;

        return (0.2126 * $lineal($r) + 0.7152 * $lineal($g) + 0.0722 * $lineal($b)) < 0.45;
    }

    /**
     * El logo como data URI, para los PDF.
     *
     * Incrustado y no enlazado: un PDF se guarda, se reenvia y se abre sin
     * sesion y sin red, y un logo por su direccion saldria roto justo en el
     * documento que va a leer quien decide.
     *
     * Manda la version larga, y es donde mas se nota: la cabecera de un
     * documento es ancha y baja, que es exactamente la forma de una marca
     * horizontal. Sin larga vale la compacta, y sin ninguna la del archivo de
     * configuracion.
     */
    public static function logoParaPdf(): ?string
    {
        $disco = \Illuminate\Support\Facades\Storage::disk('public');

        if ($ruta = self::logoLargo() ?? self::logo()) {
            return self::comoDataUri($disco->get($ruta), $disco->mimeType($ruta) ?: null, $ruta);
        }

        $deFabrica = (string) config('fabos.lab.logo');
        $archivo = $deFabrica !== '' ? public_path($deFabrica) : null;

        return $archivo && is_file($archivo)
            ? self::comoDataUri((string) file_get_contents($archivo), null, $deFabrica)
            : null;
    }

    /**
     * El logo como dirección web, para la barra del panel y para el icono del
     * navegador (§3).
     *
     * El logo subido salía en el sitio público y en los PDF, pero no en el
     * favicon ni en el panel: esos dos tenían su propio camino —un archivo
     * fijo en `public/`— y se quedaron con la marca vieja. El resultado es el
     * peor de los dos mundos: la marca nueva arriba y la vieja en la pestaña,
     * en la misma pantalla.
     *
     * Solo el subido, y nulo si no hay: el respaldo lo pone cada sitio, que no
     * es el mismo. La barra del panel cae al logo del archivo de
     * configuración, y la pestaña a los iconos de `public/img`, que están
     * hechos a la medida que pide un favicon —cosa que un logo cualquiera no
     * tiene por qué cumplir—. Esconder aquí un respaldo único haría que el
     * caso por defecto empeorara sin que se viera dónde.
     *
     * Manda el archivo que se subio para esto, si lo hay: a dieciseis pixeles
     * un trazo fino desaparece, y lo que funciona ahi suele ser otro dibujo y
     * no la marca encogida. Sin el vale la compacta, y sin compacta la larga
     * —al reves que en el PDF, donde manda la larga—, porque un icono
     * apretado es mejor que ninguno.
     *
     * @return array{url:string,tipo:string,svg:bool}|null
     */
    public static function logoParaLaWeb(): ?array
    {
        $ruta = self::favicon() ?? self::logo() ?? self::logoLargo();

        return $ruta
            ? self::conSuTipo(\Illuminate\Support\Facades\Storage::disk('public')->url($ruta), $ruta)
            : null;
    }

    /**
     * Las dos versiones para la barra, con su tipo y su direccion.
     *
     * @return array{larga:?array{url:string,tipo:string,svg:bool},compacta:?array{url:string,tipo:string,svg:bool}}
     */
    public static function marcaParaLaBarra(): array
    {
        $disco = \Illuminate\Support\Facades\Storage::disk('public');

        return [
            'larga' => ($l = self::logoLargo()) ? self::conSuTipo($disco->url($l), $l) : null,
            'compacta' => ($c = self::logo()) ? self::conSuTipo($disco->url($c), $c) : null,
        ];
    }

    /**
     * El tipo sale de la extensión y no del disco.
     *
     * Un SVG suele llegar del disco como «text/plain», y un `<link rel=icon>`
     * con ese tipo el navegador lo descarta sin decir nada: la pestaña se
     * queda con el icono de antes y parece que no se guardó.
     *
     * @return array{url:string,tipo:string,svg:bool}
     */
    private static function conSuTipo(string $url, string $ruta): array
    {
        $extension = strtolower(pathinfo($ruta, PATHINFO_EXTENSION));

        return [
            'url'  => $url,
            'tipo' => match ($extension) {
                'svg'  => 'image/svg+xml',
                'png'  => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                'gif'  => 'image/gif',
                'webp' => 'image/webp',
                'ico'  => 'image/x-icon',
                default => 'image/png',
            },
            'svg' => $extension === 'svg',
        ];
    }

    private static function comoDataUri(string $contenido, ?string $tipo, string $ruta): ?string
    {
        // Por la extension cuando el disco no sabe decirlo: un SVG suele
        // llegar como «text/plain» y en el PDF no se pintaria.
        $tipo = match (strtolower(pathinfo($ruta, PATHINFO_EXTENSION))) {
            'svg'  => 'image/svg+xml',
            'png'  => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            default => $tipo,
        };

        return $tipo && str_starts_with($tipo, 'image/')
            ? 'data:' . $tipo . ';base64,' . base64_encode($contenido)
            : null;
    }

    /** La base del acuerdo de alianza: varias partes que aportan (§11). */
    public const ALIANZA_CLAUSULAS = 'proyectos.alianza_clausulas';

    /** La tienda cobra si los cobros generales estan encendidos, o si se encendio ella sola. */
    public static function cobrosEnTienda(): bool
    {
        return self::cobrosActivos() || (bool) Setting::get(self::COBROS_TIENDA, false);
    }

    public static function carnetLoginEnabled(): bool
    {
        return (bool) Setting::get(self::CARNET_LOGIN, false);
    }

    public static function otpLoginEnabled(): bool
    {
        // Unica excepcion a "cerrado por defecto": si se apagaran los dos
        // metodos nadie podria entrar nunca mas, ni siquiera a reactivarlos.
        return (bool) Setting::get(self::OTP_LOGIN, true);
    }
}
