<?php

namespace Database\Seeders;

use App\Models\Supply;
use App\Services\Money\PricingService;
use Illuminate\Database\Seeder;

/**
 * Descuentos por cantidad para los productos que ya estaban (§14).
 *
 * Treinta y un productos con precio y **cero escalones**. El sistema sabía
 * aplicarlos desde siempre; nadie los había escrito, así que pedir uno y pedir
 * cincuenta costaba lo mismo — y en un fablab eso no es cierto: el montaje se
 * reparte, la lámina se aprovecha entera, la máquina se para una vez y no
 * cincuenta.
 *
 * ## No se inventan precios nuevos
 *
 * Los escalones se calculan **desde el precio que ya tiene cada producto**, que
 * es una decisión del laboratorio y no se toca. Lo único que se añade es cuánto
 * baja al llevar más.
 *
 * ## Por qué los umbrales cambian con el precio
 *
 * Una escalera única —10, 25, 50— sirve para un llavero y es absurda para una
 * figura de $280.000: de esas no se piden cincuenta ni una vez al año, así que
 * el descuento nunca se aplicaría y sería decoración en la ficha.
 *
 * Tres bandas, con la misma idea detrás: **el primer escalón cae donde de
 * verdad empieza a haber pedidos repetidos de esa cosa.**
 *
 *   · barato   (< $25.000)  →  10, 25 y 50
 *   · medio    (< $80.000)  →   5, 15 y 40
 *   · caro     (≥ $80.000)  →   3, 10 y 25
 *
 * Redondeado a los quinientos pesos: un descuento que deja un precio de
 * $37.437 parece un error de cálculo, no una decisión.
 */
class EscalonesDelCatalogoSeeder extends Seeder
{
    /**
     * Las tres escaleras: `desde => cuánto baja`.
     *
     * Los porcentajes bajan al subir la banda porque el margen absoluto ya es
     * mayor: un 25% sobre $280.000 son setenta mil pesos regalados por pedir
     * veinticinco, y eso ya no es un descuento por volumen sino otro precio.
     */
    private const ESCALERAS = [
        'barato' => [10 => 0.10, 25 => 0.18, 50 => 0.25],
        'medio' => [5 => 0.08, 15 => 0.15, 40 => 0.22],
        'caro' => [3 => 0.07, 10 => 0.13, 25 => 0.20],
    ];

    public function run(): void
    {
        $precios = app(PricingService::class);

        foreach (Supply::where('kind', 'producto')->orderBy('name')->get() as $producto) {
            // Quien ya tiene escalones decidio los suyos: no se pisan.
            if ($producto->priceBreaks()->exists()) {
                $this->command?->line("  · ya tenía escalones: {$producto->name}");

                continue;
            }

            $base = $precios->aPesos($precios->precioDe($producto, 1));

            // Sin precio no hay de donde descontar.
            if ($base < 1) {
                $this->command?->warn("  · sin precio: {$producto->name}");

                continue;
            }

            foreach (self::ESCALERAS[$this->banda($base)] as $desde => $descuento) {
                $producto->priceBreaks()->create([
                    'min_quantity' => $desde,
                    'price_minor' => $precios->aMenor($this->redondeado($base * (1 - $descuento))),
                ]);
            }

            $this->command?->info(sprintf(
                '  ✓ %-38s $%s → %s',
                mb_substr($producto->name, 0, 38),
                number_format($base, 0, ',', '.'),
                collect(self::ESCALERAS[$this->banda($base)])
                    ->map(fn ($d, $desde) => $desde.':$'.number_format($this->redondeado($base * (1 - $d)), 0, ',', '.'))
                    ->implode('  '),
            ));
        }
    }

    private function banda(int $pesos): string
    {
        return match (true) {
            $pesos < 25_000 => 'barato',
            $pesos < 80_000 => 'medio',
            default => 'caro',
        };
    }

    /** A los quinientos: un precio de $37.437 parece un error, no un descuento. */
    private function redondeado(float $pesos): int
    {
        return (int) (round($pesos / 500) * 500);
    }
}
