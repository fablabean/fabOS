<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Material consumido en una reserva (§12, §7).
 *
 * Cierra el círculo entre las tres cosas que hasta ahora vivían separadas: la
 * existencia del insumo, lo que la persona paga y el costo real de la sesión.
 * Se registra al cerrar, no al reservar, porque nadie sabe de antemano cuántos
 * gramos va a gastar.
 *
 * El precio se congela en la línea: cambiar la tarifa del filamento mañana no
 * debe reescribir lo que costó una impresión de la semana pasada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_supplies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supply_id')->constrained()->cascadeOnDelete();

            $table->decimal('quantity', 12, 3);
            $table->bigInteger('unit_price_minor')->default(0);   // FabCoins

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_supplies');
    }
};
