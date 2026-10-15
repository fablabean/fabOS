<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que se compra se describe como lo describe la tienda (§9).
 *
 * El título de un producto de Amazon pasa de 255 caracteres sin esfuerzo
 * —«Creality Official K2 Plus Nozzles, 0.4/0.6/0.8mm 4PCS Quick-Swap…»—, y su
 * enlace también. Con `varchar(255)` la línea no se guardaba y la requisición
 * quedaba creada sin nada dentro. Pasan a texto, aquí y en la lista de deseos,
 * de donde salen muchas de esas líneas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_request_items', function (Blueprint $table) {
            $table->text('description')->change();
            $table->text('supplier')->nullable()->change();
            $table->text('reference_url')->nullable()->change();
        });

        Schema::table('wishes', function (Blueprint $table) {
            $table->text('description')->change();
            $table->text('reference_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        // A propósito no se devuelve a 255: truncaría lo que ya se guardó.
    }
};
