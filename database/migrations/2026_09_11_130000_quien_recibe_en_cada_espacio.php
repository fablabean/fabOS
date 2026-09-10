<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quién recibe en cada espacio, por defecto (§10).
 *
 * Una sala con dueño claro —el Lab. de Cómputo, el taller— tiene a alguien
 * que la conoce. Si esa persona está en jornada cuando alguien reserva, es
 * quien recibe; si no, el sistema elige como siempre entre quienes están.
 * Es por espacio, y manda sobre el responsable del área.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            $table->foreignId('host_id')->nullable()->after('shares_seats')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            $table->dropConstrainedForeignId('host_id');
        });
    }
};
