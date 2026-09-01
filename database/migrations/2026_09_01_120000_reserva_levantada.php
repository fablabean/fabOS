<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuando se levanto una reserva caida (§8).
 *
 * La ventana para validar la llegada se mide desde la hora de inicio, y eso
 * deja a una reserva levantada nacida fuera de plazo: se devuelve a las nueve
 * de la noche una que empezaba a las cinco, y el primer intento de escanear la
 * vuelve a marcar como no presentada.
 *
 * Guardando cuando se devolvio, la tolerancia se cuenta desde ahi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->timestamp('reinstated_at')->nullable()->after('checked_out_at');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('reinstated_at');
        });
    }
};
