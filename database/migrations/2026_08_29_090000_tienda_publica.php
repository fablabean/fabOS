<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La tienda que se puede mirar sin entrar (§14).
 *
 * Hasta ahora el catálogo solo existía para quien ya tenía cuenta, y era una
 * lista de insumos. Pero lo que el laboratorio ofrece son tres cosas
 * distintas, y confundirlas obliga a explicarlas en cada conversación:
 *
 *  · **Insumos**: material que se lleva quien va a fabricar. Sale del
 *    inventario.
 *  · **Productos terminados**: algo ya hecho que se lleva puesto. También sale
 *    del inventario —es una existencia como cualquier otra—, pero quien lo
 *    compra no viene a fabricar nada.
 *  · **Servicios listos**: un trabajo con precio cerrado, «corte láser por
 *    hoja». No tiene existencia: se hace cuando alguien lo pide.
 *
 * Los dos primeros comparten tabla porque comparten lo que importa: se cuentan,
 * se descuentan y se reponen. Separarlos daría dos inventarios que habría que
 * cuadrar entre sí.
 *
 * Y `is_public`, porque **no todo lo que hay se vende**: el laboratorio compra
 * acetona y brocas que no le va a vender a un estudiante. Un catálogo que
 * enseña el almacén entero obliga a explicar por qué no se puede comprar la
 * mitad de lo que aparece.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplies', function (Blueprint $table) {
            // insumo | producto
            $table->string('kind')->default('insumo')->after('name');

            $table->boolean('is_public')->default(false)->after('is_active');
            $table->string('photo_path')->nullable()->after('description');
            $table->text('public_description')->nullable()->after('photo_path');
        });

        Schema::create('service_offerings', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();

            $table->text('description')->nullable();
            $table->string('unit')->default('unidad');

            // En unidades menores de FabCoin, como todo lo que se cobra.
            $table->bigInteger('price_minor')->default(0);

            // Cuánto tarda. Es lo primero que pregunta quien lo pide, y no
            // decirlo obliga a un correo de ida y vuelta antes de empezar.
            $table->unsignedSmallInteger('lead_time_days')->nullable();

            $table->string('photo_path')->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true);

            $table->timestamps();
        });

        // Lo que ya tenía precio y existencia se enseña: es justo el catálogo
        // que la tienda venía mostrando a quien entraba.
        DB::table('supplies')
            ->where('is_active', true)
            ->where('stock', '>', 0)
            ->update(['is_public' => true]);
    }

    public function down(): void
    {
        Schema::dropIfExists('service_offerings');

        Schema::table('supplies', function (Blueprint $table) {
            $table->dropColumn(['kind', 'is_public', 'photo_path', 'public_description']);
        });
    }
};
