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
     * El beneficio semanal de FabCoins (§12): cada semana, a quien tenga
     * correo de una institucion aliada, el sistema le completa el saldo
     * hasta el tope. No se acumula: quien ya tiene el tope o mas, no recibe.
     */
    public const BENEFICIO_ACTIVO   = 'beneficio.activo';
    public const BENEFICIO_SEMANAL  = 'beneficio.semanal_minor';
    public const BENEFICIO_DOMINIOS = 'beneficio.dominios';

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

    /** La base del acuerdo de servicio que redacta el sistema (§11). */
    public const ACUERDO_CLAUSULAS  = 'proyectos.acuerdo_clausulas';
    public const ACUERDO_FORMA_PAGO = 'proyectos.acuerdo_forma_pago';

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
