<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Apartar el tiempo de alguien para un proyecto (§10, §11).
 *
 * Quien lleva un proyecto necesita horas seguidas para hacerlo, y esas horas
 * no pueden repartirse a asesorías ni a acompañamientos. El sistema ya sabe
 * decir «esta persona está ocupada»: es lo que hace con una asesoría, que
 * reserva el TIEMPO de quien atiende. Un bloque de proyecto es lo mismo, con
 * un proyecto detrás y una tarea delante.
 *
 * Por eso no hay tabla nueva: el bloque es una reserva más, de la persona,
 * y todo lo que hoy mira «¿está libre?» lo ve sin que haya que enseñarle
 * nada. Solo hace falta saber PARA QUÉ tarea se apartó, y esa es la única
 * columna que se añade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->foreignId('project_task_id')
                ->nullable()
                ->after('project_id')
                ->constrained('project_tasks')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_task_id');
        });
    }
};
