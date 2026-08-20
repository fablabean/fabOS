<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidencia fotográfica en las órdenes de mantenimiento (§8).
 *
 * Una foto del antes y del después es lo que convierte «se arregló» en algo
 * comprobable dos años más tarde, cuando quien lo arregló ya no está y hay que
 * decidir si la máquina se repara otra vez o se da de baja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->json('photos')->nullable()->after('work_done');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn('photos');
        });
    }
};
