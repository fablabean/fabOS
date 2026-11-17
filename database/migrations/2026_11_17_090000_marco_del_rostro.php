<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dónde está, dentro del recorte, el rostro del que salió la huella (en
 * fracciones del recorte), para marcarlo cuando en la foto salen varias
 * personas.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['huellas_de_persona', 'visitas_del_dia'] as $tabla) {
            Schema::table($tabla, fn (Blueprint $t) => $t->json('marco')->nullable());
        }
    }

    public function down(): void
    {
        foreach (['huellas_de_persona', 'visitas_del_dia'] as $tabla) {
            Schema::table($tabla, fn (Blueprint $t) => $t->dropColumn('marco'));
        }
    }
};
