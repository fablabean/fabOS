<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La imagen de referencia del proyecto (§11).
 *
 * Un listado de proyectos es una lista de nombres, y «Señalética para
 * Bienestar» no se distingue de «Señalética para el edificio B» hasta que uno
 * abre los dos. Una imagen los separa de un vistazo, y en la propuesta hace
 * algo más importante: enseña de qué se está hablando antes de que nadie lea
 * una sola línea.
 *
 * Suele ser una de las que se mandaron en la propuesta, así que se puede tomar
 * de ahí en vez de volver a subirla.
 *
 * Va al **disco privado**, como todo lo que sube alguien de fuera: se sirve por
 * una ruta que comprueba quién pide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('reference_image_path')->nullable()->after('summary');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('reference_image_path');
        });
    }
};
