<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lotes de candidatos (§11).
 *
 * A veces no llega un proyecto: llega una **lista**. Veinte spin-offs de la
 * incubadora, los ganadores de una convocatoria, los semilleros de una
 * facultad. El laboratorio no puede tomarlos todos, así que hay que mirarlos
 * uno a uno y decidir cuáles entran.
 *
 * Sin un sitio para eso, la lista vive en un Excel que alguien reenvía, se
 * evalúa en una reunión, y lo acordado se pierde entre la reunión y el momento
 * de arrancar. Aquí la lista entra entera, se evalúa dentro, y **lo aceptado se
 * convierte en proyecto sin volver a teclear nada**.
 *
 * El candidato **no es** un proyecto todavía, y por eso vive aparte: darle
 * código de proyecto a algo que probablemente no se acepte ensucia el único
 * sitio donde se mira si el laboratorio entrega.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_batches', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('source')->nullable();          // quién manda la lista
            $table->text('description')->nullable();
            $table->date('received_on')->nullable();

            // abierto | evaluado | cerrado
            $table->string('status')->default('abierto');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('candidate_batches')->cascadeOnDelete();

            $table->string('name');
            $table->string('organization')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->text('description')->nullable();

            // pendiente | aceptado | descartado
            $table->string('status')->default('pendiente');

            // De 1 a 5, para poder ordenar la lista por lo que mas promete. Es
            // una nota, no un algoritmo: quien evalua decide.
            $table->unsignedTinyInteger('score')->nullable();

            $table->text('evaluation_note')->nullable();
            $table->timestampTz('evaluated_at')->nullable();
            $table->foreignId('evaluated_by')->nullable()->constrained('users')->nullOnDelete();

            // El proyecto en que se convirtio, si se acepto. Nullable: la
            // mayoria no llega a serlo, y eso es lo normal.
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();

            $table->index(['batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidates');
        Schema::dropIfExists('candidate_batches');
    }
};
