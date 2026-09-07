<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Descuentos y cobros adicionales en una solicitud de compra (§13).
 *
 * Las lineas del carrito sumaban bien y aun asi la cuenta no daba: Amazon
 * aplica un descuento sobre el pedido, cobra el envio aparte, y a veces un
 * cargo de importacion. Nada de eso es una linea con cantidad y precio
 * unitario, y meterlo como si lo fuera —«envio, 1 unidad, US$23»— era
 * mentir para que cuadrara.
 *
 * Van en una tabla aparte, con su signo y con si llevan impuesto o no: un
 * descuento del proveedor baja la base sobre la que se calcula el IVA; un
 * cargo de importacion normalmente no lo lleva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_request_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained()->cascadeOnDelete();
            // descuento | cobro
            $table->string('kind', 20);
            $table->string('description', 160);
            // Siempre positivo, en la moneda de la solicitud: el signo lo pone
            // el tipo.
            $table->decimal('amount', 14, 2)->default(0);
            $table->boolean('applies_tax')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_request_adjustments');
    }
};
