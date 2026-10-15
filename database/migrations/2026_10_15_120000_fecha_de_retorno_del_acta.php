<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuándo debe volver lo que sale (§8).
 *
 * El formato de Planta Física no lo pide; el laboratorio sí lo necesita: un
 * robot prestado sin fecha de vuelta es un robot que nadie reclama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('actas', function (Blueprint $table) {
            $table->date('fecha_retorno')->nullable()->after('fecha');
        });
    }

    public function down(): void
    {
        Schema::table('actas', function (Blueprint $table) {
            $table->dropColumn('fecha_retorno');
        });
    }
};
