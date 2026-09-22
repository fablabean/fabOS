<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una jornada programada puede ser por una reserva (§5, §7).
 *
 * El caso más común de jornada fuera del patrón: alguien pidió el laboratorio
 * de VR un sábado, se aprobó, y a quien lo abre se le programa la jornada.
 * Hasta ahora eso quedaba como texto en el motivo («Apertura por la solicitud
 * #280»); ahora la jornada cuelga de la reserva: desde la reserva se ve quién
 * la abre, y desde la jornada se abre la reserva con un clic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->foreignId('reservation_id')->nullable()->after('project_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reservation_id');
        });
    }
};
