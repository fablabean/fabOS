<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las medidas de la lámina, para poder gastar un trozo (§13).
 *
 * El MDF se compra en hojas de 120×90 y se gasta en pedazos de 30×40. Al
 * cerrar una producción solo se podía declarar «cuántas láminas», y de una
 * hoja entera no se gastó ninguna: se gastó una novena parte. O se anotaba
 * una lámina completa —y el inventario descontaba de más, y el proyecto
 * pagaba de más— o se hacía la regla de tres a mano.
 *
 * Con las medidas guardadas, quien cierra escribe el trozo que cortó y el
 * sistema saca la fracción. El tamaño vive en el insumo y no en su nombre,
 * que es donde estaba —«MDF 5.5 mm (hoja 120×90)»— y donde no sirve para
 * calcular nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplies', function (Blueprint $table) {
            $table->decimal('largo_cm', 8, 2)->nullable()->after('unit');
            $table->decimal('ancho_cm', 8, 2)->nullable()->after('largo_cm');
        });

        // Y un decimal más en lo declarado: un recorte de 5×5 en una hoja de
        // 120×90 es 0,0023 de lámina, y con tres decimales se guardaba como
        // 0,002. Es el mismo cero de la tarifa por centímetro, más pequeño.
        Schema::table('reservation_supplies', function (Blueprint $table) {
            $table->decimal('quantity', 14, 4)->change();
        });
    }

    public function down(): void
    {
        Schema::table('supplies', function (Blueprint $table) {
            $table->dropColumn(['largo_cm', 'ancho_cm']);
        });

        Schema::table('reservation_supplies', function (Blueprint $table) {
            $table->decimal('quantity', 12, 3)->change();
        });
    }
};
