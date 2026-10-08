<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un FabCoin, un minuto.
 *
 * Nació en cinco, pensando solo en lo que gana quien invita. Pero cualquier
 * FabCoin compra tiempo, y a cinco minutos los ocho de la bienvenida eran
 * cuarenta minutos de consola para todo el que tuviera cuenta. A uno, la
 * bienvenida son ocho minutos y registrarse por la página da uno más.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('iot_dispositivos', function (Blueprint $table) {
            $table->unsignedSmallInteger('minutos_por_fabcoin')->default(1)->change();
        });

        DB::table('iot_dispositivos')->where('minutos_por_fabcoin', 5)->update(['minutos_por_fabcoin' => 1]);
    }

    public function down(): void
    {
        Schema::table('iot_dispositivos', function (Blueprint $table) {
            $table->unsignedSmallInteger('minutos_por_fabcoin')->default(5)->change();
        });
    }
};
