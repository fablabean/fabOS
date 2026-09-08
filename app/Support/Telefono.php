<?php

namespace App\Support;

/**
 * Un telefono con su indicativo de pais, guardado como un solo texto.
 *
 * Se pide en dos partes —el indicativo y el numero— porque asi nadie se
 * olvida del pais ni lo escribe de tres formas distintas, y se guarda como
 * «+57 3001234567» en la misma columna de siempre: lo que ya estaba escrito
 * sin indicativo se sigue leyendo, y se entiende como de Colombia.
 */
class Telefono
{
    public const POR_DEFECTO = '+57';

    /** Los indicativos que se ofrecen. Colombia primero; el resto, por nombre. */
    public const INDICATIVOS = [
        '+57'  => 'CO +57',
        '+54'  => 'AR +54',
        '+55'  => 'BR +55',
        '+1'   => 'CA/US +1',
        '+56'  => 'CL +56',
        '+506' => 'CR +506',
        '+593' => 'EC +593',
        '+503' => 'SV +503',
        '+34'  => 'ES +34',
        '+502' => 'GT +502',
        '+504' => 'HN +504',
        '+52'  => 'MX +52',
        '+505' => 'NI +505',
        '+507' => 'PA +507',
        '+595' => 'PY +595',
        '+51'  => 'PE +51',
        '+598' => 'UY +598',
        '+58'  => 'VE +58',
    ];

    /** «+57» y «300 123 4567» → «+57 3001234567». Sin numero, nulo. */
    public static function componer(?string $indicativo, ?string $numero): ?string
    {
        $numero = preg_replace('/[^\d]/', '', (string) $numero);

        if ($numero === '') {
            return null;
        }

        $indicativo = '+' . preg_replace('/[^\d]/', '', (string) ($indicativo ?: self::POR_DEFECTO));

        return $indicativo . ' ' . $numero;
    }

    /**
     * «+57 3001234567» → indicativo y numero. Lo guardado sin indicativo se
     * entiende como de Colombia.
     *
     * @return array{indicativo:string,numero:string}
     */
    public static function partir(?string $telefono): array
    {
        $telefono = trim((string) $telefono);

        if (! str_starts_with($telefono, '+')) {
            return ['indicativo' => self::POR_DEFECTO, 'numero' => $telefono];
        }

        // Con espacio, el corte es claro. Sin espacio, se prueba con los
        // indicativos conocidos, del mas largo al mas corto: «+34600…» es
        // Espana, no un pais «+3460».
        if (preg_match('/^(\+\d{1,4})\s+(.*)$/', $telefono, $m)) {
            return ['indicativo' => $m[1], 'numero' => trim($m[2])];
        }

        $conocidos = array_keys(self::INDICATIVOS);
        usort($conocidos, fn ($a, $b) => strlen($b) <=> strlen($a));

        foreach ($conocidos as $indicativo) {
            if (str_starts_with($telefono, $indicativo)) {
                return ['indicativo' => $indicativo, 'numero' => substr($telefono, strlen($indicativo))];
            }
        }

        preg_match('/^(\+\d{1,3})(.*)$/', $telefono, $m);

        return ['indicativo' => $m[1] ?? self::POR_DEFECTO, 'numero' => trim($m[2] ?? $telefono)];
    }
}
