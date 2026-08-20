<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * El proveedor de correo no aceptó el código de ingreso.
 *
 * Existe para que un fallo del proveedor no salga como error 500. fabOS no
 * tiene contraseñas: si el correo no sale, nadie entra, y la persona merece
 * saber que el problema no es suya y que puede reintentar — no una pantalla
 * de «Server Error» que no dice nada y parece culpa del navegador.
 */
class EnvioDeCodigoFallido extends RuntimeException
{
}
