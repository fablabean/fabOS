<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jornada del equipo (§5).
 *
 * El horario deja de ser una costumbre y pasa a ser un dato. De aquí se DERIVA
 * la franja atendida del laboratorio: hoy 7:30–17:30 porque esa es la envolvente
 * de los turnos, no porque alguien lo escribiera. Unas vacaciones la encogen sin
 * que nadie tenga que acordarse de actualizar nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Patrón semanal: 1 = lunes … 7 = domingo (ISO-8601).
            $table->unsignedTinyInteger('weekday');
            $table->time('starts_at');
            $table->time('ends_at');

            // El descanso importa: 8:00–17:30 son 9,5 h; con una hora de
            // almuerzo quedan 8,5 diarias y 42,5 semanales, que ya roza el tope.
            $table->unsignedSmallInteger('break_minutes')->default(60);

            // Vigencia: cambia con el contrato, sin borrar el anterior.
            $table->date('effective_from');
            $table->date('effective_until')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'weekday']);
        });

        Schema::create('schedule_exceptions', function (Blueprint $table) {
            $table->id();
            // Nulo = aplica a todo el laboratorio (festivo, cierre).
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();

            // vacaciones | incapacidad | permiso | comision | festivo | cierre
            $table->string('kind');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'starts_on', 'ends_on']);
        });

        Schema::create('shift_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Jornada puntual fuera del patrón semanal: un sábado de
            // acompañamiento, un evento, una apertura extraordinaria (§5).
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('reason');

            // Consume del tope de extras salvo que se compense con tiempo.
            $table->boolean('counts_as_overtime')->default(true);

            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('accepted_at')->nullable();
            $table->text('conflict_note')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_assignments');
        Schema::dropIfExists('schedule_exceptions');
        Schema::dropIfExists('work_schedules');
    }
};
