<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una edicion puede no tener fechas (§9).
 *
 * La teoria y el examen no exigen coincidir con nadie: se leen y se hacen
 * cuando la persona pueda. Lo unico que necesita agenda es la practica, y esa
 * se acuerda despues, cuando ya hay a quien evaluar.
 *
 * Obligar a poner fechas para poder abrir el curso significaba abrir una
 * edicion nueva cada mes solo para que alguien pudiera empezar a leer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_editions', function (Blueprint $table) {
            $table->date('starts_on')->nullable()->change();
            $table->date('ends_on')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('course_editions', function (Blueprint $table) {
            $table->date('starts_on')->nullable(false)->change();
            $table->date('ends_on')->nullable(false)->change();
        });
    }
};
