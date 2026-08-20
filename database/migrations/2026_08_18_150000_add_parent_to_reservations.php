<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enlaza el bloque del colaborador con la reserva que acompaña.
 *
 * Antes se emparejaban comparando fechas, y eso es frágil: cualquier
 * diferencia de precisión —microsegundos, por ejemplo— hacía que la reserva del
 * acompañante NO se soltara al cancelar, dejándolo ocupado por algo que ya no
 * existe. Un identificador explícito no tiene ese problema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->foreignId('parent_reservation_id')
                ->nullable()
                ->after('supervisor_id')
                ->constrained('reservations')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_reservation_id');
        });
    }
};
