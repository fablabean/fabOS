<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuándo abrió el proyecto, por primera vez, quien lo lleva. Sin abrir sale
 * así en la lista: es lo que nadie ha mirado todavía.
 *
 * Lo que ya estaba se da por visto, salvo las solicitudes de la última
 * semana que siguen en idea sin propuesta: esas son justo las que pueden
 * estar esperando a que alguien las abra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->timestamp('opened_at')->nullable()->after('proposal_sent_at');
        });

        DB::table('projects')
            ->where(fn ($q) => $q->where('stage', '!=', 'idea')
                ->orWhereNotNull('proposal_sent_at')
                ->orWhere('created_at', '<', now()->subDays(7)))
            ->update(['opened_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('opened_at');
        });
    }
};
