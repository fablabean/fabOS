<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compras en dolares, y precios con centavos (§13).
 *
 * El precio unitario era un entero en pesos: en Colombia los centavos no se
 * usan y arrastrarlos solo produce totales que no cuadran. Pero buena parte
 * de lo que se compra viene de Amazon, y ahi un lanyard vale US$18,99: al
 * escribirlo, la base lo rechazaba y la solicitud no se guardaba.
 *
 * Dos cosas: el precio admite decimales, y la solicitud dice en que moneda
 * esta -pesos o dolares- y a cuantos pesos va el dolar. El presupuesto sigue
 * en pesos enteros: lo que cambia es como se llega a ellos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->string('currency', 3)->default('COP')->after('tax_rate');
            // Pesos por una unidad de la moneda. Nulo en pesos.
            $table->decimal('exchange_rate', 12, 2)->nullable()->after('currency');
        });

        Schema::table('purchase_request_items', function (Blueprint $table) {
            $table->decimal('unit_price', 14, 2)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_request_items', function (Blueprint $table) {
            $table->bigInteger('unit_price')->default(0)->change();
        });

        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropColumn(['currency', 'exchange_rate']);
        });
    }
};
