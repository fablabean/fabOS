<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que nos preguntan, y qué les contestamos (§10).
 *
 * La guía lleva semanas respondiendo y no queda rastro de nada. Y ahí está lo
 * más valioso que produce: la gente escribe con sus palabras qué quiere hacer
 * en el laboratorio —no lo que el catálogo le ofrece— y eso dice qué cursos
 * faltan, qué máquina nadie encuentra y qué se pide y no tenemos.
 *
 * Se guarda la pregunta y el camino sugerido, juntos: por separado no se puede
 * revisar si la guía está acertando, que es la otra pregunta que esto contesta.
 *
 * Con quién preguntó cuando había sesión. Es dato personal y por eso se dice:
 * sirve para devolver la llamada —«preguntaste por corte láser, el jueves hay
 * una inducción»—, no para perfilar a nadie. Quien entra sin cuenta queda sin
 * identificar, y así se queda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultas_de_guia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->text('texto');
            $table->string('camino', 24);
            $table->text('porque')->nullable();

            // Si se contestó de la memoria: esas no costaron una llamada a la
            // API, y sin distinguirlas el gasto no cuadra con el conteo.
            $table->boolean('de_memoria')->default(false);

            $table->timestamp('created_at')->nullable();

            $table->index('camino');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultas_de_guia');
    }
};
