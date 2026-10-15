<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que hay que hacer para que una publicación salga (§21).
 *
 * Un reel no es una tarea: es un guion, una grabación, una edición y un
 * texto, y cada cosa la suele hacer alguien distinto. Con la publicación como
 * única unidad, la parrilla decía «en diseño» durante dos semanas sin que se
 * supiera qué faltaba ni a quién preguntarle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publicacion_tareas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publicacion_id')->constrained('publicaciones')->cascadeOnDelete();
            $table->string('que', 160);
            $table->foreignId('responsable_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('vence_el')->nullable();
            $table->timestamp('hecha_at')->nullable();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();

            $table->index(['responsable_id', 'hecha_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publicacion_tareas');
    }
};
