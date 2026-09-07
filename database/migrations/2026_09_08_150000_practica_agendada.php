<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La prueba practica se agenda (§9).
 *
 * Quien aprobaba el examen teorico se quedaba con una frase —«falta la
 * evaluacion presencial»— y sin forma de pedirla: tenia que escribir a
 * alguien, y ese alguien buscar un hueco a mano. Ahora la pide como una
 * asesoria: el sistema le ofrece las horas en que alguien del area puede
 * verla, y reserva el tiempo de esa persona.
 *
 * Es una reserva mas, del tiempo de quien evalua, con modo `practica`; lo
 * unico nuevo es saber de que inscripcion viene, para que al firmarla se
 * cierre sola y para que el panel diga «agendada el jueves con Michael».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->foreignId('enrollment_id')->nullable()->after('advisory_area_id')
                ->constrained('enrollments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('enrollment_id');
        });
    }
};
