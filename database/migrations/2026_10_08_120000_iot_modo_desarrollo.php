<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modo desarrollo de un dispositivo: se comporta como conectado aunque no
 * haya ningún aparato preguntando. Para probar el registro, la fila y los
 * FabCoins antes de que la Raspberry exista.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('iot_dispositivos', function (Blueprint $table) {
            $table->boolean('modo_desarrollo')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('iot_dispositivos', function (Blueprint $table) {
            $table->dropColumn('modo_desarrollo');
        });
    }
};
