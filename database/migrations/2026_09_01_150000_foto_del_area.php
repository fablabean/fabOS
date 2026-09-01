<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La foto de cada area (§7).
 *
 * La pagina de reservas empieza eligiendo area, y hasta ahora esas tarjetas se
 * ilustraban con la foto de una de sus maquinas. Sirve para arrancar sin subir
 * nada, pero la maquina que sale la elige el orden alfabetico: «Impresion 3D»
 * acababa representada por un secador de filamento.
 *
 * Con foto propia, el area se enseña como el laboratorio quiere que se vea. Sin
 * ella se sigue usando la de una maquina: nadie se queda con un hueco gris por
 * no haber subido nada todavia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('areas', function (Blueprint $table) {
            $table->string('photo_path')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('areas', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });
    }
};
