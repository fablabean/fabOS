<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuántas veces suena un anuncio al lanzarlo a mano, y con qué pausa (§3).
 *
 * El horario fijo ya tenía las suyas; esto es para el favorito de la barra de
 * arriba: «evacuar el edificio» no se dice una sola vez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('anuncios', function (Blueprint $table) {
            $table->unsignedTinyInteger('repeticiones')->default(1)->after('sonido_previo');
            $table->unsignedSmallInteger('pausa')->default(30)->after('repeticiones');
        });
    }

    public function down(): void
    {
        Schema::table('anuncios', function (Blueprint $table) {
            $table->dropColumn(['repeticiones', 'pausa']);
        });
    }
};
