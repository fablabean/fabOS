<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuándo reprobó, para poder citar de nuevo (§9).
 *
 * Quien hace el curso, aprueba la teoría y no pasa la evaluación presencial
 * quedaba «no aprobado» y ahí se acababa: solo retirarse. Y una práctica
 * fallida no es un veredicto para siempre: es «todavía no». Pasada una
 * semana se le puede citar otra vez, y para contar esa semana hace falta
 * saber cuándo reprobó, que hasta hoy no quedaba escrito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->timestampTz('failed_at')->nullable()->after('practical_notes');
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropColumn('failed_at');
        });
    }
};
