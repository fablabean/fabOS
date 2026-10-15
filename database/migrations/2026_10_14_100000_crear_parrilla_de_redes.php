<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La parrilla de redes (§21).
 *
 * Lo que se va a publicar, cuándo, en qué red, quién lo hace y en qué va. Antes
 * vivía en una hoja de cálculo de alguien, y el laboratorio se enteraba de lo
 * que se había publicado al verlo en Instagram.
 *
 * Aquí solo se planea: la publicación se hace en cada red, y se vuelve a
 * marcar como publicada con su enlace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publicaciones', function (Blueprint $table) {
            $table->id();
            $table->string('titulo', 160);
            $table->string('estado', 20)->default('idea')->index();
            $table->json('redes')->nullable();
            $table->string('formato', 20)->nullable();
            $table->string('pilar', 20)->nullable();
            $table->timestamp('programada_para')->nullable()->index();
            $table->text('texto')->nullable();
            $table->text('hashtags')->nullable();
            $table->text('notas')->nullable();
            $table->json('piezas')->nullable();
            $table->foreignId('responsable_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('creada_por')->nullable()->constrained('users')->nullOnDelete();

            // La aprobación: quién y cuándo, o por qué se devolvió.
            $table->foreignId('aprobada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('aprobada_at')->nullable();
            $table->text('devuelta_con')->nullable();

            $table->string('enlace', 500)->nullable();
            $table->timestamp('publicada_at')->nullable();

            // De dónde salió: la edición, el proyecto o la página que cuenta.
            $table->nullableMorphs('origen');

            // La sugerencia que la creó, para no volver a ofrecerla.
            $table->string('sugerencia', 120)->nullable()->index();

            $table->timestamps();
        });

        // El material del banco de contenido que va en cada publicación.
        Schema::create('contenido_publicacion', function (Blueprint $table) {
            $table->foreignId('publicacion_id')->constrained('publicaciones')->cascadeOnDelete();
            $table->foreignId('contenido_id')->constrained('contenidos')->cascadeOnDelete();
            $table->primary(['publicacion_id', 'contenido_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contenido_publicacion');
        Schema::dropIfExists('publicaciones');
    }
};
