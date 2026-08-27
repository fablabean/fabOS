<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Categorías de insumos y política de reposición (§13).
 *
 * **Categorías anidadas.** Un almacén de laboratorio crece por acumulación:
 * primero son veinte insumos y se leen de un vistazo, luego son doscientos y
 * hace falta bajar por «Madera → MDF» en vez de recorrer una lista alfabética
 * donde el MDF de 3 mm y el de 6 mm quedan separados por un tornillo.
 *
 * Se anidan a cualquier profundidad porque la realidad de cada laboratorio es
 * distinta: uno separará por material, otro por área, otro por proveedor.
 * Fijar dos niveles obligaría a inventar categorías falsas al tercero.
 *
 * **Mínimo y máximo.** El mínimo ya existía —`reorder_point`, por debajo del
 * cual el insumo entra al carrito de reposición—; faltaba el máximo, que es lo
 * que dice **cuánto** pedir. Sin él, quien repone sabe que hay que comprar pero
 * no cuánto, y acaba comprando lo que le parece: de más y ocupa bodega, o de
 * menos y en dos semanas vuelve a faltar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supply_categories', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('slug')->unique();

            // Se anidan a cualquier profundidad: «Madera → MDF → 3 mm».
            $table->foreignId('parent_id')->nullable()
                ->constrained('supply_categories')->nullOnDelete();

            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();

            $table->index(['parent_id', 'position']);
        });

        Schema::table('supplies', function (Blueprint $table) {
            // Nula a proposito: un insumo sin clasificar sigue siendo usable.
            // Obligar a categorizar antes de poder anotar algo hace que la
            // gente invente una categoria «Varios» y ahi acabe todo.
            $table->foreignId('category_id')->nullable()->after('area_id')
                ->constrained('supply_categories')->nullOnDelete();

            // Hasta cuanto reponer. Con el punto de reposicion, dice cuanto
            // pedir: max - existencia.
            $table->decimal('max_stock', 12, 3)->nullable()->after('reorder_point');
        });
    }

    public function down(): void
    {
        Schema::table('supplies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
            $table->dropColumn('max_stock');
        });

        Schema::dropIfExists('supply_categories');
    }
};
