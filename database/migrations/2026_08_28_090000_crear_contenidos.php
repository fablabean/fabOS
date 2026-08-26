<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Banco de contenido del laboratorio (§21).
 *
 * Lo que pasa en un fablab se documenta con el teléfono o no se documenta. Una
 * pieza saliendo de la impresora a las once de la noche, el primer corte que
 * salió bien: si eso hay que descargarlo del teléfono, pasarlo a un computador
 * y subirlo a una carpeta, no ocurre. Este módulo existe para que ocurra en el
 * mismo minuto, desde la cámara.
 *
 * Dos cosas que no son accesorias:
 *
 *  · **La autorización se guarda, no se supone.** El material se comparte con
 *    Comunicaciones de la Universidad, y compartir algo de lo que no se tienen
 *    derechos es un problema de la institución, no del archivo. Se anota quién
 *    autorizó, cuándo, y **qué texto exacto aceptó** —los términos cambian, y
 *    una autorización que apunta a un texto que ya no existe no prueba nada—.
 *  · **El archivo va al disco privado.** Es material de personas: sale de aquí
 *    cuando alguien lo pide y tiene permiso, nunca por una URL adivinable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contenidos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Si había un proyecto activo, el material queda con él.
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();

            // foto | video
            $table->string('kind')->default('foto');

            $table->string('file_path');
            $table->string('original_name')->nullable();
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            $table->string('title')->nullable();
            $table->text('description')->nullable();

            // La autorización, con su texto. No basta un booleano: hay que poder
            // decir qué se aceptó exactamente y cuándo.
            $table->timestampTz('rights_accepted_at');
            $table->string('rights_version');

            // Se comparte con Comunicaciones salvo que alguien lo retire. Se
            // retira, no se borra: el archivo sigue siendo del proyecto.
            $table->timestampTz('withdrawn_at')->nullable();
            $table->string('withdrawn_reason')->nullable();

            $table->timestamps();

            $table->index(['project_id', 'created_at']);
            $table->index(['kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contenidos');
    }
};
