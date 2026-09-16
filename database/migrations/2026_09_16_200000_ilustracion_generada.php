<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La ilustración generada, en su propia columna (§14).
 *
 * El catálogo tiene decenas de cosas sin foto, y una ficha sin imagen se salta
 * al mirar. Generar una ayuda — pero una imagen inventada de un producto que
 * alguien va a comprar no es una foto, y presentarla como tal es prometer un
 * acabado que nadie ha fabricado.
 *
 * Por eso **no comparte columna con la foto de verdad**. Podría haberse hecho
 * con una casilla «esta foto es generada» junto a `photo_path`, y habría sido
 * peor: esa casilla hay que acordarse de apagarla el día que alguien suba la
 * foto real, y eso no se hace. Con dos columnas la regla se cae de madura:
 *
 *   hay foto real  →  se enseña la foto, sin más
 *   no hay         →  se enseña la ilustración, marcada como tal
 *
 * La ilustración no se borra al subir la foto: deja de verse, que es distinto.
 * Si mañana se retira la foto, vuelve a haber algo que enseñar.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['supplies', 'service_offerings'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->string('ilustracion_path')->nullable();

                // Con qué se pidió. Sirve para dos cosas: volver a generarla
                // afinando el texto, y poder responder de dónde salió esa
                // imagen cuando alguien pregunte.
                $table->text('ilustracion_prompt')->nullable();
                $table->timestamp('ilustracion_generada_el')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['supplies', 'service_offerings'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropColumn(['ilustracion_path', 'ilustracion_prompt', 'ilustracion_generada_el']);
            });
        }
    }
};
