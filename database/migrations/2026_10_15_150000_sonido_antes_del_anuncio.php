<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un sonido antes del anuncio, como en un aeropuerto (§3).
 *
 * Una voz que arranca de golpe por los parlantes se pierde las primeras
 * palabras: la gente todavía no está escuchando. El tono avisa de que viene
 * algo. Cada anuncio elige el suyo; los que ya había llevan el de aeropuerto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('anuncios', function (Blueprint $table) {
            $table->string('sonido_previo', 20)->default('aeropuerto')->after('voz');
        });
    }

    public function down(): void
    {
        Schema::table('anuncios', function (Blueprint $table) {
            $table->dropColumn('sonido_previo');
        });
    }
};
