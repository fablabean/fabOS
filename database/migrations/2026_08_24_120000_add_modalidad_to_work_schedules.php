<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Presencial o remota (§5).
 *
 * No es una etiqueta informativa. La franja atendida del laboratorio se DERIVA
 * de las jornadas vigentes, y de ahí sale si el laboratorio está abierto y
 * quién puede acompañar una reserva. Alguien trabajando desde casa no abre la
 * puerta ni supervisa una máquina, así que la modalidad cambia el cálculo.
 *
 * Por defecto `presencial`: es lo que eran todas las jornadas hasta ahora, y
 * suponer lo contrario cerraría el laboratorio de golpe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_schedules', function (Blueprint $table) {
            $table->string('modalidad', 20)->default('presencial')->after('break_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('work_schedules', function (Blueprint $table) {
            $table->dropColumn('modalidad');
        });
    }
};
