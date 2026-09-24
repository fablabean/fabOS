<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un curso puede enseñar su precio también en dólares (§9, §12).
 *
 * Fab Academy tiene un precio en dólares —lo pone la Fab Foundation— y lo mira
 * gente de fuera del país. Verlo solo en pesos obliga a cada uno a buscar la
 * tasa, y escribirlo a mano en el resumen lo deja envejeciendo: la TRM cambia
 * a diario y el texto no.
 *
 * Es por curso y no para todos: el precio en dólares de un taller de soldadura
 * de cuatro horas no le interesa a nadie, y ponérselo sería ruido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->boolean('mostrar_usd')->default(false)->after('price_minor');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('mostrar_usd');
        });
    }
};
