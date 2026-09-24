<?php

namespace App\Support;

/**
 * Pasar de FabCoins a pesos y al revés, en un solo sitio (§12).
 *
 * Todo importe se guarda en **unidades menores de FabCoin**, que es como lo
 * lleva el libro contable. Lo que cambia es en qué se escribe y en qué se lee:
 * una hora de máquina se piensa en FabCoins y un material por centímetro
 * cuadrado se piensa en pesos, y pedirle a quien tarifa que traduzca de cabeza
 * es pedirle que se equivoque.
 *
 * Vivía repartido —un `/100` aquí, un `aPesos()` allá, una tasa leída a mano en
 * una vista— y cada sitio elegía sus decimales. Aquí está una vez.
 */
final class Dinero
{
    /** 1 FabCoin en unidades menores. */
    public static function unidades(): int
    {
        return (int) config('fabos.currency.minor_units');
    }

    /** Cuántos pesos vale un FabCoin. Es un supuesto administrable. */
    public static function tasa(): float
    {
        return (float) config('fabos.currency.peso_rate');
    }

    /** Unidades menores → lo que se teclea, en la moneda que sea. */
    public static function enMoneda(float $menor, ?string $moneda): float
    {
        return $moneda === 'pesos'
            ? $menor / self::unidades() * self::tasa()
            : $menor / self::unidades();
    }

    /**
     * Lo tecleado → unidades menores, que es como se guarda.
     *
     * Con cuatro decimales: un cm² de MDF vale 0,4 unidades menores, y
     * redondear aquí evita que lo escrito y lo guardado difieran en el último
     * dígito.
     */
    public static function aMenor(float $escrito, ?string $moneda): float
    {
        $menor = $moneda === 'pesos'
            ? $escrito / self::tasa() * self::unidades()
            : $escrito * self::unidades();

        return round($menor, 4);
    }

    /**
     * Un importe para leerlo: sin ceros de relleno y sin quedarse en «0,00».
     *
     * Con dos decimales fijos, 0,004 FabCoins se mostraba como «0,00» —el mismo
     * cero que costó un material saliendo gratis—. Los decimales salen solo si
     * los hay.
     */
    public static function enTexto(float $menor, ?string $moneda = 'fbc'): string
    {
        $valor = self::enMoneda($menor, $moneda);
        $texto = number_format($valor, $moneda === 'pesos' ? 2 : 4, ',', '.');

        if (str_contains($texto, ',')) {
            $texto = rtrim(rtrim($texto, '0'), ',');
        }

        return $texto . ' ' . self::simbolo($moneda);
    }

    /** Lo mismo, como lo escribe un campo numérico: con punto y sin relleno. */
    public static function comoSeTeclea(float $menor, ?string $moneda): string
    {
        $valor = self::enMoneda($menor, $moneda);
        $texto = number_format($valor, $moneda === 'pesos' ? 2 : 4, '.', '');

        return str_contains($texto, '.') ? rtrim(rtrim($texto, '0'), '.') : $texto;
    }

    public static function simbolo(?string $moneda): string
    {
        return $moneda === 'pesos'
            ? (string) config('fabos.money.symbol')
            : (string) config('fabos.currency.code');
    }

    /** La otra: sirve para decir la equivalencia al lado de lo que se escribe. */
    public static function laOtra(?string $moneda): string
    {
        return $moneda === 'pesos' ? 'fbc' : 'pesos';
    }

    /** @return array<string,string> */
    public static function monedas(): array
    {
        return [
            'fbc'   => (string) config('fabos.currency.name') . 's',
            'pesos' => 'Pesos',
        ];
    }
}
