<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cupo por opción en una pregunta de selección (§9).
 *
 * «Grupo 1 de 10 a 1, grupo 2 de 2 a 5, cinco cupos cada uno»: la misma
 * actividad, el mismo día, con la gente repartida por una pregunta del
 * formulario. Cada opción lleva su número en `options` ({texto, cupo}); esta
 * casilla dice que ese número se hace cumplir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registration_questions', function (Blueprint $table) {
            $table->boolean('capacity_per_option')->default(false)->after('required');
        });
    }

    public function down(): void
    {
        Schema::table('registration_questions', function (Blueprint $table) {
            $table->dropColumn('capacity_per_option');
        });
    }
};
