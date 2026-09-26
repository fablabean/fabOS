<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decir si la guía acertó, y con qué debió contestar (§10).
 *
 * El registro ya decía qué se preguntó y qué se contestó, pero no si estuvo
 * bien. Eso lo sabe quien lee la lista y reconoce el caso —«ese venía con el
 * archivo listo, no era asesoría»—, y hasta ahora ese conocimiento se quedaba
 * en su cabeza o, con suerte, en una regla escrita a mano.
 *
 * Lo corregido vuelve a la guía como ejemplo: no es una encuesta de
 * satisfacción, es la forma de que el mismo error no se repita la semana que
 * viene. Por eso se guarda el camino correcto y no sólo un pulgar abajo: un
 * «esto está mal» sin el «debió ser esto» no le sirve de nada al modelo.
 *
 * `nota` es para el matiz que no cabe en elegir un camino —«si dice “para una
 * clase” es espacio, no fabricación»—, y viaja con el ejemplo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultas_de_guia', function (Blueprint $table) {
            // Nulo es «nadie la ha mirado», que es distinto de «estuvo mal».
            $table->boolean('acerto')->nullable()->after('de_memoria');
            $table->string('camino_corregido', 20)->nullable()->after('acerto');
            $table->text('nota')->nullable()->after('camino_corregido');
            $table->foreignId('revisada_por')->nullable()->after('nota')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('revisada_el')->nullable()->after('revisada_por');

            // Se consulta «lo corregido, lo último primero» cada vez que se
            // arma el mensaje a la API: sin índice es recorrer la tabla entera
            // en mitad de algo que ya tarda.
            $table->index(['acerto', 'id'], 'consultas_guia_acerto_idx');
        });
    }

    public function down(): void
    {
        Schema::table('consultas_de_guia', function (Blueprint $table) {
            $table->dropIndex('consultas_guia_acerto_idx');
            $table->dropConstrainedForeignId('revisada_por');
            $table->dropColumn(['acerto', 'camino_corregido', 'nota', 'revisada_el']);
        });
    }
};
