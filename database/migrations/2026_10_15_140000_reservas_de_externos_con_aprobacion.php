<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que reserva por su cuenta alguien de fuera, lo confirma la coordinación.
 *
 * Un externo pidió un recorrido para quince personas y quedó confirmado solo,
 * porque había alguien del equipo en jornada. Recibir a un grupo de otra
 * institución es una decisión, no un hueco en la agenda. Cada categoría dice
 * si sus reservas pasan por aprobación; las que no son de la institución, sí.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_categories', function (Blueprint $table) {
            $table->boolean('requires_approval')->default(false)->after('can_reserve');
        });

        DB::table('user_categories')->where('is_institutional', false)->update(['requires_approval' => true]);
    }

    public function down(): void
    {
        Schema::table('user_categories', function (Blueprint $table) {
            $table->dropColumn('requires_approval');
        });
    }
};
