<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asesoria general de un area (§10).
 *
 * Hasta ahora una asesoria era siempre sobre UNA maquina, y hay que saber cual
 * antes de pedirla. Quien llega con «quiero imprimir esto en 3D» todavia no
 * sabe si le toca la Prusa o la de resina: elegir maquina es parte de lo que
 * viene a consultar.
 *
 * La reserva sigue siendo del tiempo de quien asesora; lo unico que cambia es
 * sobre que se pidio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->foreignId('advisory_area_id')
                ->nullable()
                ->after('advisory_asset_id')
                ->constrained('areas')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('advisory_area_id');
        });
    }
};
