<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los anuncios con voz (§3).
 *
 * Un aviso que se escribe en el panel y se oye en el sitio: el horario de
 * diciembre, la feria del jueves, la máquina que está en mantenimiento. El
 * audio lo genera el servidor de voz del FabLab y se guarda: el anuncio es
 * fijo, así que se pide UNA vez y se vuelve a pedir solo cuando cambia lo que
 * dice.
 *
 * `audio_de` es la huella del texto y la voz con que se generó el audio. Es lo
 * que permite saber, sin volver a pedirlo, si el audio guardado todavía dice
 * lo que dice el anuncio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anuncios', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);

            $table->string('titulo');
            $table->text('texto');
            $table->string('voz', 20)->default('mujer');

            $table->string('audio_path')->nullable();
            $table->string('audio_de', 64)->nullable();
            $table->string('audio_estado', 20)->default('pendiente');
            $table->string('audio_error')->nullable();
            $table->timestamp('audio_generado_el')->nullable();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->timestamps();

            $table->index(['is_active', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anuncios');
    }
};
