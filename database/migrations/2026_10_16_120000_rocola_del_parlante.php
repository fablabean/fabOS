<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La rocola: cualquiera con FabCoins pone una canción en el Parlante (§3).
 *
 * Cada pedido queda anotado —quién, qué canción, si entró a la cola o se le
 * devolvió el FabCoin—, para que el equipo vea quién puso qué y para poder
 * explicar cualquier cobro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canciones_pedidas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('spotify_id', 40);
            $table->string('titulo', 300);
            $table->string('artista', 300)->nullable();
            $table->string('portada_url', 500)->nullable();
            $table->unsignedInteger('duracion_ms')->default(0);
            $table->unsignedInteger('cobrado_minor')->default(0);
            $table->string('estado', 20)->default('en_cola');   // en_cola | devuelta
            $table->string('motivo', 300)->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canciones_pedidas');
    }
};
