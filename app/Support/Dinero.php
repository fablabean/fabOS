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

    /**
     * Cuántos pesos vale un dólar, hoy.
     *
     * La TRM de verdad, no el supuesto de la configuración: un programa que se
     * vende en dólares —Fab Academy— no se cotiza con la tasa del año pasado.
     * La consulta y sus respaldos viven en `TasaDeCambio`.
     */
    public static function tasaUsd(): float
    {
        return app(\App\Services\Money\TasaDeCambio::class)->pesosPorDolar();
    }

    /** Unidades menores → lo que se teclea, en la moneda que sea. */
    public static function enMoneda(float $menor, ?string $moneda): float
    {
        $fabcoins = $menor / self::unidades();

        return match ($moneda) {
            'pesos' => $fabcoins * self::tasa(),
            // Por los pesos, que es donde vive la equivalencia: un FabCoin son
            // mil pesos, y los pesos por dólar los pone la TRM del día.
            'usd'   => $fabcoins * self::tasa() / self::tasaUsd(),
            default => $fabcoins,
        };
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
        $menor = match ($moneda) {
            'pesos' => $escrito / self::tasa() * self::unidades(),
            'usd'   => $escrito * self::tasaUsd() / self::tasa() * self::unidades(),
            default => $escrito * self::unidades(),
        };

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
        $texto = number_format($valor, self::decimales($moneda), ',', '.');

        if (str_contains($texto, ',')) {
            $texto = rtrim(rtrim($texto, '0'), ',');
        }

        return $texto . ' ' . self::simbolo($moneda);
    }

    /** Lo mismo, como lo escribe un campo numérico: con punto y sin relleno. */
    public static function comoSeTeclea(float $menor, ?string $moneda): string
    {
        $valor = self::enMoneda($menor, $moneda);
        $texto = number_format($valor, self::decimales($moneda), '.', '');

        return str_contains($texto, '.') ? rtrim(rtrim($texto, '0'), '.') : $texto;
    }

    /**
     * Cuántos decimales tiene sentido escribir en cada una.
     *
     * Cuatro en FabCoins porque un cm² de material vale milésimas; dos en las
     * de verdad, que es como se escriben los precios.
     */
    private static function decimales(?string $moneda): int
    {
        return in_array($moneda, ['pesos', 'usd'], true) ? 2 : 4;
    }

    public static function simbolo(?string $moneda): string
    {
        return match ($moneda) {
            'pesos' => (string) config('fabos.money.symbol'),
            'usd'   => 'USD',
            default => (string) config('fabos.currency.code'),
        };
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
            // El dólar, para lo que se vende fuera: Fab Academy tiene un
            // precio en dólares y traducirlo a mano cada semestre, con una
            // tasa que cambia a diario, es como se cotiza de menos.
            'usd'   => 'Dólares',
        ];
    }
}
