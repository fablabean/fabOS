<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Descuentos por cantidad (§14).
 *
 * Un laboratorio cobra distinto una pieza que veinte: el montaje se reparte, la
 * lámina se aprovecha entera, la máquina se para una vez y no veinte. Hasta
 * ahora eso se negociaba por WhatsApp y se cobraba a ojo, que es como dos
 * personas acaban pagando precios distintos por lo mismo.
 *
 * Se guarda el **precio unitario** de cada escalón, no un porcentaje. El
 * porcentaje se mueve solo cuando cambia el precio base —y entonces se cobra
 * algo que nadie decidió—; el precio dice exactamente lo que se cobra. El
 * descuento que se enseña se calcula al mostrarlo.
 *
 * Es polimórfico porque la misma idea vale para un insumo y para un servicio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_breaks', function (Blueprint $table) {
            $table->id();
            $table->morphs('priceable');

            // Desde cuántas unidades aplica. Con decimales: hay cosas que se
            // venden por gramo, y el escalón útil está en los 500.
            $table->decimal('min_quantity', 12, 3);

            // En unidades menores de FabCoin, como todo el libro.
            $table->bigInteger('price_minor');

            $table->timestamps();

            // Dos escalones que arrancan en la misma cantidad no se pueden
            // resolver: gana el que se leyó primero, y eso no es una regla.
            $table->unique(['priceable_type', 'priceable_id', 'min_quantity'], 'escalon_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_breaks');
    }
};
