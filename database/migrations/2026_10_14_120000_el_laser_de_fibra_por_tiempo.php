<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El láser de fibra, igual que el CO₂: se paga el tiempo (§12).
 *
 * 8 FabCoins la media hora, sin montaje, mínimo ni garantía: se retiene lo que
 * cuesta el tiempo reservado. Aquí no se ofrece material —marca metal, y el
 * metal lo trae quien viene—.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tarifa = DB::table('rate_cards')->where('slug', 'familia-fibra')->first();

        if (! $tarifa) {
            return;
        }

        $n = fn ($menor) => rtrim(rtrim(number_format($menor / 100, 2, ',', '.'), '0'), ',');

        DB::table('rate_cards')->where('id', $tarifa->id)->update([
            'unit'          => 'hora',
            'price_minor'   => 1600,
            'setup_minor'   => 0,
            'minimum_minor' => 0,
            'deposit_minor' => 0,
            // Lo anterior queda anotado, por si hay que volver.
            'notes'         => trim('8 FabCoins por cada 30 minutos; se retiene lo que cuesta el tiempo reservado. '
                . 'Hasta el 2026-10-13: ' . $n($tarifa->price_minor) . ' la hora, ' . $n($tarifa->setup_minor) . ' de montaje, '
                . $n($tarifa->minimum_minor) . ' de mínimo y ' . $n($tarifa->deposit_minor) . ' de garantía. '
                . (string) $tarifa->notes),
            'updated_at'    => now(),
        ]);
    }

    public function down(): void
    {
        // Los valores de antes están en las notas de la tarifa.
    }
};
