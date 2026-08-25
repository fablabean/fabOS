<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Preguntas y respuestas del laboratorio (§20).
 *
 * Lo que hoy se resuelve en un pasillo —«¿esta resina sirve para moldes?»—
 * se responde una vez y queda para quien pregunte lo mismo dentro de un mes.
 * Ese es todo el objetivo: que el conocimiento del laboratorio deje de vivir
 * solo en la cabeza de quien lleva más tiempo.
 *
 * **La respuesta la publica una persona, siempre.** Puede haber un borrador
 * sugerido, pero una respuesta equivocada sobre cómo operar una máquina no es
 * un error de texto: es un riesgo físico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->string('slug')->unique();
            $table->text('body');

            // Sobre qué trata. Ambas opcionales: quien pregunta no siempre sabe
            // a qué área pertenece su duda, y obligarle a clasificarla es
            // pedirle que sepa la respuesta antes de preguntar.
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status', 20)->default('abierta');
            $table->unsignedInteger('vistas')->default(0);

            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        // Búsqueda en español, con lematización: quien busca «impresoras»
        // encuentra «impresora», y quien busca «lavado» encuentra «lavar».
        // PostgreSQL lo trae de serie, así que no hace falta ninguna pieza más.
        DB::statement("
            ALTER TABLE questions
            ADD COLUMN busqueda tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('spanish', coalesce(title, '')), 'A') ||
                setweight(to_tsvector('spanish', coalesce(body, '')), 'B')
            ) STORED
        ");

        DB::statement('CREATE INDEX questions_busqueda_idx ON questions USING gin (busqueda)');

        Schema::create('answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();

            // Nulo cuando la redactó la IA y todavía nadie la ha hecho suya.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->text('body');

            // De dónde salió el texto. Se guarda aunque una persona lo haya
            // corregido: quien lee tiene derecho a saber que hubo una máquina
            // en el origen.
            $table->string('origen', 20)->default('persona');

            // Nada se ve hasta que alguien lo aprueba.
            $table->boolean('publicada')->default(false);
            $table->timestamp('publicada_at')->nullable();
            $table->foreignId('aprobada_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['question_id', 'publicada']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('answers');
        Schema::dropIfExists('questions');
    }
};
