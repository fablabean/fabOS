<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El trámite sale de la categoría, y los comentarios de la propuesta (§11).
 *
 * **Quién pide ya está dicho en su categoría.** Un profesor y un colaborador
 * son institución: su encargo se paga con un traslado presupuestal. Un
 * estudiante no. Alguien de fuera, tampoco. Preguntárselo a quien ya entró
 * sería preguntar algo que el sistema sabe, y dejar que se equivoque en la
 * respuesta.
 *
 * Se guarda **en la categoría y no en código** porque cada laboratorio arma sus
 * categorías: el día que aparezca «egresado» o «aliado», quien coordina decide
 * qué trámite le toca sin que nadie despliegue nada.
 *
 * Y los comentarios. Una propuesta que solo se puede aceptar o ignorar obliga a
 * salir del sistema para decir «casi, pero cambia la fecha» —y esa frase acaba
 * en un chat donde nadie la vuelve a encontrar—.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_categories', function (Blueprint $table) {
            // interno | estudiante | externo
            $table->string('client_kind')->default('externo')->after('is_institutional');
        });

        // Lo institucional paga por traslado; el estudiante se identifica
        // aparte porque su trámite es el más corto de los tres.
        DB::table('user_categories')->where('is_institutional', true)->update(['client_kind' => 'interno']);
        DB::table('user_categories')->where('slug', 'estudiante')->update(['client_kind' => 'estudiante']);

        Schema::create('project_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Puede no tener cuenta: se responde por el enlace del correo.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author_name')->nullable();

            // cliente | laboratorio — quién habla, para leer el hilo de un vistazo
            $table->string('side')->default('cliente');

            $table->text('body');

            $table->timestamps();

            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_comments');

        Schema::table('user_categories', function (Blueprint $table) {
            $table->dropColumn('client_kind');
        });
    }
};
