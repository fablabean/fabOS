<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una orden preventiva es la revisión rutinaria del INV-118 (§6.2): queda con
 * el esquema de color con que se revisó y con su diagnóstico (§6.3: todo
 * bien, problemas menores o problemas importantes). Con eso la orden cerrada
 * se descarga como el «Informe de Diagnóstico de Revisión Rutinaria».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->string('resultado_revision', 20)->nullable()->after('diagnosis');
            $table->string('esquema_revision', 20)->nullable()->after('resultado_revision');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn(['resultado_revision', 'esquema_revision']);
        });
    }
};
