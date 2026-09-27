<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Los computadores de la sala de cómputo, como computadores (§8).
 *
 * Se cargaron como herramientas —«Computador 1» a «Computador 8»— porque así
 * se prestaban. Ahora el tipo computador se presta igual (Asset::DE_PRESTAMO),
 * así que se reclasifican sin cambiar cómo se reservan: siguen marcándose
 * dentro de la sala, gratis y sin certifab.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('assets')
            ->where('kind', 'herramienta')
            ->where('name', 'ilike', 'computador%')
            ->update(['kind' => 'computador', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('assets')
            ->where('kind', 'computador')
            ->where('name', 'ilike', 'computador%')
            ->update(['kind' => 'herramienta']);
    }
};
