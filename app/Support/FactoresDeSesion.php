<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Con qué demostró su identidad quien está en esta sesión (§5, §16).
 *
 * fabOS no tiene contraseñas: tiene varias formas de demostrar quién eres, y
 * ninguna es «la buena». Lo que importa no es cuál se usó sino **cuántas
 * distintas**, porque cada una prueba algo diferente:
 *
 *   correo → controlas ese buzón
 *   app    → tienes el teléfono donde vive el secreto
 *   carne  → tienes la sesión viva de la app de la Universidad
 *
 * Una basta para reservar una máquina. Para el backoffice —donde se encienden
 * los cobros, se cambian tarifas y se mira el libro contable— hacen falta dos,
 * y da igual cuáles: lo que protege no es una combinación concreta, es que un
 * solo teléfono robado, o un solo buzón comprometido, no alcance.
 */
final class FactoresDeSesion
{
    public const CORREO = 'correo';
    public const APP    = 'app';
    public const CARNE  = 'carne';

    private const CLAVE = 'factores_de_identidad';

    /**
     * La misma clave, expuesta para las pruebas.
     *
     * Las pruebas necesitan colocar una sesion ya autenticada sin repetir el
     * ingreso entero. Que la constante sea publica —en vez de que cada prueba
     * escriba la cadena a mano— hace que renombrarla no deje doce pruebas
     * pasando en verde contra una clave que ya no existe.
     */
    public const CLAVE_PRUEBAS = self::CLAVE;

    public static function anotar(Request $request, string $factor): void
    {
        $factores = self::de($request);
        $factores[$factor] = true;

        $request->session()->put(self::CLAVE, $factores);
    }

    /** @return array<string,bool> */
    public static function de(Request $request): array
    {
        $factores = $request->session()->get(self::CLAVE, []);

        return is_array($factores) ? $factores : [];
    }

    public static function cuantos(Request $request): int
    {
        return count(self::de($request));
    }

    public static function tiene(Request $request, string $factor): bool
    {
        return self::de($request)[$factor] ?? false;
    }

    /**
     * Se limpia al iniciar sesión, no al cerrarla: `session()->regenerate()`
     * conserva los datos, así que sin esto los factores de la sesión anterior
     * seguirían contando para la siguiente persona que entrara en ese navegador.
     */
    public static function olvidar(Request $request): void
    {
        $request->session()->forget(self::CLAVE);
    }
}
