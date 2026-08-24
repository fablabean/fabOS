<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sobre qué equipo trata una asesoría (§10).
 *
 * Una asesoría es una reserva del **tiempo de quien asesora** —`reservable` es
 * esa persona—, así que hace falta un sitio aparte para decir de qué máquina se
 * está hablando. La máquina no se reserva: muchas asesorías son de consulta,
 * revisar un diseño o planear un trabajo, y bloquearla dejaría el equipo
 * parado sin necesidad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->foreignId('advisory_asset_id')->nullable()->after('project_id')
                ->constrained('assets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('advisory_asset_id');
        });
    }
};
