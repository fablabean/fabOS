<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las páginas del sitio (§3, portal público).
 *
 * El banner ya dejaba cambiar lo primero que se ve sin desplegar código. Lo
 * que faltaba era el sitio donde cabe lo que no es una frase: contar un
 * proyecto entero, anunciar una convocatoria con sus fechas, armar la página
 * a la que lleva el botón del banner.
 *
 * Tres decisiones que están en las columnas y conviene no perder:
 *
 *  · **Los bloques van en un JSON, no en una tabla aparte.** Una página es una
 *    lista ordenada de piezas heterogéneas —un párrafo, una galería, unas
 *    cifras— que solo se leen enteras y siempre juntas. En tablas serían tres
 *    o cuatro relaciones y un `order` que mantener a mano, para nunca
 *    consultar un bloque por separado. Aquí se lee la página y ya están.
 *  · **La vigencia, igual que el banner.** Lo que anuncia un evento se apaga
 *    solo el día que el evento pasa. Si depende de que alguien se acuerde, no
 *    se apaga.
 *  · **`project_id` es de dónde salió, no qué se enseña.** La página nace
 *    sembrada con lo publicable del proyecto y a partir de ahí es contenido
 *    propio: se edita, se recorta, se le añade lo que no estaba registrado.
 *    Que leyera el proyecto en vivo sería publicar sin mirar, y ahí dentro hay
 *    el valor acordado y el documento del cliente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paginas', function (Blueprint $table) {
            $table->id();

            // La dirección es parte del contenido: se dice en voz alta y se
            // imprime en un QR. Por eso se escribe, no se deriva del título
            // -que cambia- ni del id -que no se puede leer en voz alta-.
            $table->string('slug')->unique();

            $table->string('titulo');
            $table->string('rotulo')->nullable();
            $table->text('resumen')->nullable();
            $table->string('portada_path')->nullable();

            $table->json('bloques')->nullable();

            // De qué proyecto se sembró. Se conserva para saber de dónde vino
            // y para no ofrecer dos veces la misma página; si el proyecto se
            // borra, la página sigue: ya es contenido del laboratorio.
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();

            /*
             * Nace APAGADA, al revés que el banner.
             *
             * Una lámina se escribe para publicarla ya. Una página se siembra
             * con datos de un proyecto y hay que mirarla antes: el defecto
             * tiene que ser el que no publica nada sin que alguien lo lea.
             */
            $table->boolean('is_active')->default(false);

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['is_active', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paginas');
    }
};
