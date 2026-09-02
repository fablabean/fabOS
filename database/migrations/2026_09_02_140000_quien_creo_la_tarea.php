<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quién creó la tarea (§11).
 *
 * Las tareas sabían a quién están asignadas y no quién las escribió. Con eso
 * basta para el tablero, pero no para decidir quién puede tocarlas: alguien que
 * trabaja en un proyecto sin tener la sección de Proyectos abierta maneja **lo
 * que él creó**, y sin esta columna no había forma de saber qué era suyo.
 *
 * Las que ya existen se quedan sin autor a propósito. Rellenarlo con el
 * responsable del proyecto sería inventar un dato —nadie sabe hoy quién escribió
 * cada una—, y encima le daría a esa persona permisos que nadie le dio. Sin
 * autor, la tarea la maneja quien tenga la sección o quien lidere el proyecto,
 * que es como funcionaba hasta ahora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_tasks', function (Blueprint $table) {
            $table->foreignId('created_by')
                ->nullable()
                ->after('assigned_to')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
