<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Páginas sin el marco del sitio: solo sus módulos (§3).
 *
 * Una página que se muestra en una pantalla de un evento, o que se incrusta
 * en otro sitio, no quiere el menú del laboratorio arriba ni el pie abajo:
 * quiere su contenido y nada más. Cada página elige.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paginas', function (Blueprint $table) {
            $table->string('plantilla', 20)->default('sitio')->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('paginas', function (Blueprint $table) {
            $table->dropColumn('plantilla');
        });
    }
};
