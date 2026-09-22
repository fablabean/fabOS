<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La franja con la que cada área encabeza su sección (§3, §7).
 *
 * La foto del área es cuadrada y sirve para elegirla: sale en la cuadrícula
 * de «elige un área». Pero cuando ya estás dentro de una lista larga —las
 * herramientas, todas juntas y agrupadas por área— lo único que separa una
 * sección de la siguiente es un renglón de texto, y a media página nadie sabe
 * ya en qué área va.
 *
 * El banner es otra cosa que la foto: un recorte ancho y bajo, pensado para
 * leerse de reojo mientras se baja. Por eso es su propio campo y no un
 * encuadre de la foto: la misma imagen no sirve para las dos cosas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('areas', function (Blueprint $table) {
            $table->string('banner_path')->nullable()->after('photo_path');
        });
    }

    public function down(): void
    {
        Schema::table('areas', function (Blueprint $table) {
            $table->dropColumn('banner_path');
        });
    }
};
