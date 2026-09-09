<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que se adjunta en una respuesta queda pegado a esa respuesta.
 *
 * Hasta ahora un archivo que llegaba con un comentario iba a los soportes del
 * proyecto y el hilo solo decia «Adjuntó: foto.jpg». Servia para trabajar,
 * pero no para conversar: la foto del avance que manda el laboratorio tiene
 * que verse debajo de lo que dijo, no en una lista aparte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evidencias', function (Blueprint $table) {
            $table->foreignId('project_comment_id')
                ->nullable()
                ->after('evidenciable_id')
                ->constrained('project_comments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('evidencias', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_comment_id');
        });
    }
};
