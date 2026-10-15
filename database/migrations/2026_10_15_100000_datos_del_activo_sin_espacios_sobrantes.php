<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Placa, marca, modelo y serie sin espacios ni tabuladores sobrantes.
 *
 * Venían pegados de una hoja de cálculo: «B42D200P3PDN5KA⇥» no es la serie
 * que se busca, y en el acta sale con el hueco.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['asset_tag', 'brand', 'model', 'serial'] as $campo) {
            DB::statement("UPDATE assets SET {$campo} = NULLIF(BTRIM({$campo}, E' \\t\\r\\n'), '') WHERE {$campo} IS NOT NULL AND {$campo} <> BTRIM({$campo}, E' \\t\\r\\n')");
        }

        foreach (['placa', 'marca', 'modelo', 'serie'] as $campo) {
            DB::statement("UPDATE acta_items SET {$campo} = NULLIF(BTRIM({$campo}, E' \\t\\r\\n'), '') WHERE {$campo} IS NOT NULL AND {$campo} <> BTRIM({$campo}, E' \\t\\r\\n')");
        }
    }

    public function down(): void {}
};
