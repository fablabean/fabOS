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
