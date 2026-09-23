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

    /** La ruta del logo subido, en el disco publico. Nula si no hay. */
    public static function logo(): ?string
    {
        $ruta = trim((string) Setting::get(self::MARCA_LOGO, ''));

        return $ruta !== '' && \Illuminate\Support\Facades\Storage::disk('public')->exists($ruta) ? $ruta : null;
    }

    /**
     * El logo como data URI, para los PDF.
     *
     * Incrustado y no enlazado: un PDF se guarda, se reenvia y se abre sin
     * sesion y sin red, y un logo por su direccion saldria roto justo en el
     * documento que va a leer quien decide.
     *
     * Vale el subido; si no hay, el del archivo de configuracion.
     */
    public static function logoParaPdf(): ?string
    {
        $disco = \Illuminate\Support\Facades\Storage::disk('public');

        if ($ruta = self::logo()) {
            return self::comoDataUri($disco->get($ruta), $disco->mimeType($ruta) ?: null, $ruta);
        }

        $deFabrica = (string) config('fabos.lab.logo');
        $archivo = $deFabrica !== '' ? public_path($deFabrica) : null;

        return $archivo && is_file($archivo)
            ? self::comoDataUri((string) file_get_contents($archivo), null, $deFabrica)
            : null;
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
