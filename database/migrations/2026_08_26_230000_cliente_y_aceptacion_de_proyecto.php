<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * De quién es el encargo, y cuándo lo aceptó (§11).
 *
 * **Quién pide cambia el trámite, no el trabajo.** Un área de la propia
 * institución no paga: mueve presupuesto por la «venta interna», un circuito de
 * cuatro manos —formulario, líder que paga, líder que recibe, traslado de
 * Planeación— que no se puede correr en tres días. Un estudiante no pasa por
 * nada de eso, y una empresa de fuera tampoco: factura y ya.
 *
 * Tratarlos igual obliga a explicar el trámite en cada correo, o a no
 * explicarlo y que el encargo se quede parado esperando algo que nadie pidió.
 *
 * Y la aceptación. Sin una fecha de «sí», el proyecto avanza sobre un acuerdo
 * verbal, que es exactamente lo que las compuertas documentales existen para
 * evitar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // interno | estudiante | externo
            $table->string('client_kind')->default('externo')->after('source');

            $table->timestampTz('accepted_at')->nullable()->after('proposal_sent_at');
            $table->foreignId('accepted_by')->nullable()->after('accepted_at')
                ->constrained('users')->nullOnDelete();
            $table->text('acceptance_note')->nullable()->after('accepted_by');
        });

        // Lo que nació de una iniciativa del propio laboratorio o de gerencia
        // es interno por definicion: nadie le factura a su propia casa.
        DB::table('projects')
            ->whereIn('source', ['interno', 'gerencia'])
            ->update(['client_kind' => 'interno']);
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('accepted_by');
            $table->dropColumn(['client_kind', 'accepted_at', 'acceptance_note']);
        });
    }
};
