<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El conteo de personas con las cámaras.
 *
 * Tres tablas, de la más sensible a la que se queda:
 *  · huellas_del_dia: la huella facial de cada persona vista hoy, sin nombre.
 *    Se borra cada noche.
 *  · lecturas_de_aforo: cada cuadro analizado, solo con números.
 *  · aforo_diario: el resumen de cada día. Es lo que queda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('huellas_del_dia', function (Blueprint $t) {
            $t->id();
            $t->date('fecha')->index();
            $t->json('huella');
            $t->timestamp('primera_vez');
            $t->timestamp('ultima_vez');
            $t->unsignedInteger('veces')->default(1);
        });

        Schema::create('lecturas_de_aforo', function (Blueprint $t) {
            $t->id();
            $t->unsignedSmallInteger('camara');
            $t->timestamp('leido_at')->index();
            $t->unsignedSmallInteger('personas');
            $t->unsignedSmallInteger('identificadas');
            $t->unsignedSmallInteger('sin_identificar');
            $t->unsignedSmallInteger('nuevas');
        });

        Schema::create('aforo_diario', function (Blueprint $t) {
            $t->date('fecha')->primary();
            $t->unsignedInteger('personas_distintas')->default(0);
            $t->unsignedSmallInteger('maximo_a_la_vez')->default(0);
            $t->timestamp('maximo_at')->nullable();
            $t->unsignedSmallInteger('maximo_sin_identificar')->default(0);
            $t->unsignedInteger('lecturas')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aforo_diario');
        Schema::dropIfExists('lecturas_de_aforo');
        Schema::dropIfExists('huellas_del_dia');
    }
};
