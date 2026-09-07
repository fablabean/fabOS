<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloqueos de agenda: una franja del dia, puntual o cada semana (§5).
 *
 * Una ausencia era de dias enteros: vacaciones, incapacidad, un festivo. Pero
 * la agenda tambien se rompe por horas: alguien tiene clase de ingles los
 * jueves de cuatro a cinco hasta diciembre, o una cita el martes a las diez.
 * Sin poder decirlo, esa hora se seguia ofreciendo para asesorias y se le
 * seguian asignando acompanamientos, y el choque aparecia con la persona ya
 * en camino a su clase.
 *
 * Se guarda sobre la misma tabla, con tres columnas mas: la hora de inicio y
 * de fin —vacias, es de dia entero, como antes— y el dia de la semana en que
 * se repite, si se repite. La fecha de fin pasa a poder quedar vacia: «cada
 * jueves, hasta nuevo aviso».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_exceptions', function (Blueprint $table) {
            $table->time('starts_time')->nullable()->after('ends_on');
            $table->time('ends_time')->nullable()->after('starts_time');
            // 1 = lunes … 7 = domingo. Nulo: no se repite, va por fechas.
            $table->unsignedTinyInteger('weekday')->nullable()->after('ends_time');
            $table->date('ends_on')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('schedule_exceptions', function (Blueprint $table) {
            $table->dropColumn(['starts_time', 'ends_time', 'weekday']);
        });
    }
};
