<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuándo suena solo un anuncio (§3).
 *
 * «Cerramos en diez minutos» a las 17:50 de lunes a viernes y a las 12:50 los
 * sábados: el mismo anuncio, dos horarios. Por eso es una tabla aparte y no
 * dos columnas en el anuncio.
 *
 * `dias` son los días ISO —1 lunes … 7 domingo—. `repeticiones` y `pausa` son
 * para lo que se dice dos veces: quien estaba con la cortadora encendida no
 * oyó la primera.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anuncio_programaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('anuncio_id')->constrained('anuncios')->cascadeOnDelete();
            $table->json('dias');
            $table->time('hora');
            $table->unsignedTinyInteger('repeticiones')->default(1);
            $table->unsignedSmallInteger('pausa')->default(30);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anuncio_programaciones');
    }
};
