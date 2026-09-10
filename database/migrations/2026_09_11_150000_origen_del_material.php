<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De dónde salió el material que se gastó (§8).
 *
 * No todo lo que se consume sale del inventario del laboratorio. Un retazo
 * ya se descontó el día que se cortó la lámina, y el material que trae el
 * cliente nunca fue nuestro. Descontarlos otra vez dejaría el inventario en
 * negativo, y cobrarlos sería cobrar dos veces algo que ya se pagó.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_supplies', function (Blueprint $table) {
            $table->string('origin', 16)->default('inventario')->after('supply_id');
        });
    }

    public function down(): void
    {
        Schema::table('reservation_supplies', function (Blueprint $table) {
            $table->dropColumn('origin');
        });
    }
};
