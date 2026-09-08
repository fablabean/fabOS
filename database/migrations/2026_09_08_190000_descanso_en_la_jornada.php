<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A que hora es el descanso de cada jornada (§5).
 *
 * La jornada ya sabia cuantos minutos de descanso tenia —para las horas
 * efectivas— pero no cuando: el almuerzo de doce a una se seguia ofreciendo
 * para asesorias y se le seguian asignando acompanamientos. Con la hora de
 * inicio, y los minutos que ya estaban, esa franja queda fuera de la
 * cobertura sin agregar nada mas al formulario que un campo.
 *
 * Vacia, el descanso solo descuenta horas, como hasta ahora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_schedules', function (Blueprint $table) {
            $table->time('break_starts_at')->nullable()->after('break_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('work_schedules', function (Blueprint $table) {
            $table->dropColumn('break_starts_at');
        });
    }
};
