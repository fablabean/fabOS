<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedir un proyecto desde la web (§11).
 *
 * Hasta ahora un proyecto solo nacía si alguien del laboratorio lo anotaba, y
 * eso deja fuera al caso que más se pierde: una empresa o un estudiante que
 * escribe un domingo. Lo que llega por WhatsApp y no se anota, no existe.
 *
 * Se guarda **cuándo se mandó la propuesta**. No es adorno: es lo que permite
 * ver de un vistazo a quién se le respondió y a quién se le dejó esperando, que
 * es la diferencia entre un laboratorio que contesta y uno que parece no leer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->timestampTz('proposal_sent_at')->nullable()->after('closed_at');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('proposal_sent_at');
        });
    }
};
