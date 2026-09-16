<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un producto que se fabrica cuando alguien lo pide (§14).
 *
 * La tienda solo enseña lo que tiene existencia, y con razón: un catálogo
 * lleno de cosas que no hay erosiona la promesa de que lo que se ve se puede
 * llevar. Esa regla es correcta para un insumo — o hay lámina de MDF o no la
 * hay.
 *
 * Pero un fablab no tiene cien llaveros en un cajón: los hace cuando se los
 * piden. Con la regla de la existencia a secas, todo el catálogo de lo que el
 * laboratorio **sabe fabricar** es invisible hasta que alguien produce un lote
 * por si acaso, que es justo lo que un fablab no hace.
 *
 * Así que se separa la pregunta en dos:
 *
 *  · **¿Hay?** — la existencia, que sigue mandando para lo que se lleva hoy.
 *  · **¿Lo sabemos hacer?** — esto, que permite ofrecerlo con su plazo por
 *    delante en vez de esconderlo.
 *
 * El plazo no es decoración: es la diferencia entre «se agotó» y «te lo
 * tenemos el jueves». Sin decirlo, ofrecer algo que no está listo es prometer
 * de más.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplies', function (Blueprint $table) {
            $table->boolean('por_encargo')->default(false);

            // Días hábiles. Nulo significa «no lo hemos medido», y la tienda
            // prefiere no decir nada a decir un plazo inventado.
            $table->unsignedSmallInteger('dias_por_encargo')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('supplies', function (Blueprint $table) {
            $table->dropColumn(['por_encargo', 'dias_por_encargo']);
        });
    }
};
