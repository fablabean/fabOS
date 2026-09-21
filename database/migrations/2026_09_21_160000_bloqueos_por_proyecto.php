<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un bloqueo de agenda puede ser trabajo en uno o varios proyectos (§5, §11).
 *
 * «Bloqueo de agenda» con motivo «proyecto del dron» era lo que se escribía,
 * y no servía para nada más que para leerse. Ahora el bloqueo dice a qué
 * proyectos va —varios a la vez, que es como se trabaja una tarde— y el
 * motivo que ve quien intenta asignar esa hora lleva sus códigos. Es más
 * grueso que apartar tiempo para una tarea desde el tablero, y por eso
 * convive con ello: una tarde de proyecto no siempre es una tarea.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_schedule_exception', function (Blueprint $table) {
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('schedule_exception_id')->constrained()->cascadeOnDelete();
            $table->primary(['project_id', 'schedule_exception_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_schedule_exception');
    }
};
