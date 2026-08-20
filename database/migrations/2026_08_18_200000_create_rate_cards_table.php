<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tarifas (§12).
 *
 * Una tarifa no es un numero suelto: un trabajo en la laser cuesta tiempo de
 * maquina + montaje + material, y a veces el acompanamiento de alguien. Por eso
 * la tarjeta guarda los componentes por separado y el cobro los suma; asi se
 * puede explicar la factura linea por linea en vez de mostrar un total opaco.
 *
 * Se cuelga de lo que sea: un activo concreto, una familia de riesgo (toda la
 * impresion FDM), un espacio o un servicio. La mas especifica gana.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_cards', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');

            // A que se aplica. Nulo = tarifa por defecto del laboratorio.
            $table->nullableMorphs('rateable');

            // tiempo (por hora) | unidad (material) | fijo (un solo cobro)
            $table->string('basis')->default('tiempo');
            $table->string('unit')->nullable();            // hora, g, ml, unidad, hoja

            // Todo en unidades menores: 1 FBC = 100. Nada de decimales flotantes.
            $table->bigInteger('price_minor')->default(0);        // hora / unidad / fijo
            $table->bigInteger('setup_minor')->default(0);        // montaje, se cobra una vez
            $table->bigInteger('supervision_hour_minor')->default(0); // acompanamiento
            $table->bigInteger('minimum_minor')->default(0);      // piso del cobro
            $table->bigInteger('deposit_minor')->default(0);      // garantia al reservar

            // El reloj no se cobra al segundo: se redondea al bloque siguiente.
            $table->unsignedSmallInteger('rounding_minutes')->default(15);

            $table->boolean('is_active')->default(true);
            $table->boolean('is_assumed')->default(false); // valor supuesto, pendiente de decision
            $table->date('effective_from')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['rateable_type', 'rateable_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_cards');
    }
};
