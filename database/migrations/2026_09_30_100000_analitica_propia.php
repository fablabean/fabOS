<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Analítica propia: qué se visita, de dónde llega la gente y qué hace (§20).
 *
 * Sin cookies y sin guardar la IP. Cada visitante es una huella de 16
 * caracteres que se calcula con una sal que cambia cada día y se olvida: sirve
 * para contar visitantes y seguir un recorrido dentro del día, y no sirve para
 * reconocer a nadie al día siguiente ni para volver a la IP. Por eso no hace
 * falta aviso de cookies, y no hay un dato personal que proteger (Ley 1581).
 *
 * Lo crudo se borra a los 13 meses: alcanza para comparar un mes con el mismo
 * del año anterior, que es la comparación que importa en un laboratorio que
 * vive al ritmo de los semestres.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Cada página vista.
        Schema::create('analitica_visitas', function (Blueprint $table) {
            $table->id();
            $table->date('dia');
            $table->char('visitante', 16);
            $table->string('ruta', 300);
            $table->string('ruta_nombre', 80)->nullable();

            // La página anterior del mismo sitio, para los recorridos.
            $table->string('desde', 300)->nullable();

            // De dónde llegó: el dominio que lo mandó y en qué se resume
            // (google, instagram, chatgpt, directo…), más las utm si vinieron.
            $table->string('referente', 120)->nullable();
            $table->string('fuente', 40);
            $table->string('canal', 20);            // buscador · redes · ia · enlace · directo · campaña · interno
            $table->string('utm_source', 80)->nullable();
            $table->string('utm_medium', 80)->nullable();
            $table->string('utm_campaign', 120)->nullable();

            $table->string('dispositivo', 12);     // movil · tableta · escritorio
            $table->boolean('con_sesion')->default(false);

            $table->timestampTz('created_at');

            $table->index('dia');
            $table->index(['dia', 'visitante']);
            $table->index(['ruta', 'dia']);
        });

        // Lo que pasa además de ver: abrir un formulario (lo cuenta el
        // navegador) e inscribirse, preinscribirse, pedir un proyecto (lo
        // anota el servidor, que es quien sabe que ocurrió de verdad).
        Schema::create('analitica_eventos', function (Blueprint $table) {
            $table->id();
            $table->date('dia');
            $table->char('visitante', 16)->nullable();
            $table->string('tipo', 40);
            $table->string('ruta', 300)->nullable();
            $table->string('origen', 10);           // cliente · servidor
            $table->nullableMorphs('referencia');
            $table->json('detalle')->nullable();
            $table->timestampTz('created_at');

            $table->index(['dia', 'tipo']);
            $table->index(['ruta', 'tipo']);
        });

        // Los rastreadores que pasan: Google, Bing y los de IA. No ejecutan el
        // script, así que se anotan desde el servidor, por su user agent.
        Schema::create('analitica_rastreos', function (Blueprint $table) {
            $table->id();
            $table->date('dia');
            $table->string('bot', 40);
            $table->string('familia', 20);          // buscador · ia · redes · otro
            $table->string('ruta', 300);
            $table->unsignedSmallInteger('estado')->default(200);
            $table->timestampTz('created_at');

            $table->index(['dia', 'bot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analitica_rastreos');
        Schema::dropIfExists('analitica_eventos');
        Schema::dropIfExists('analitica_visitas');
    }
};
