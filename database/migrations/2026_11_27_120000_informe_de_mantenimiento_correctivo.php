<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El «Informe de Mantenimiento (Preventivo o Correctivo)» del INV-118 (§6.4)
 * pide, además de lo que la orden ya guarda, las piezas reemplazadas, el
 * estado en que quedó el equipo, las pruebas hechas y las recomendaciones.
 * Con eso la orden correctiva cerrada se descarga como ese informe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->jsonb('piezas_reemplazadas')->nullable()->after('work_done');
            $table->text('estado_post')->nullable()->after('piezas_reemplazadas');
            $table->text('pruebas_realizadas')->nullable()->after('estado_post');
            $table->text('recomendaciones')->nullable()->after('pruebas_realizadas');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn(['piezas_reemplazadas', 'estado_post', 'pruebas_realizadas', 'recomendaciones']);
        });
    }
};
