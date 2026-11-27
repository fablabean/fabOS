<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada vez que alguien responde la evaluación de un bootcamp: su nombre, su
 * ID si lo dio, lo que eligió y cuántas acertó. Se entra sin cuenta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluaciones_bootcamp', function (Blueprint $table) {
            $table->id();
            $table->string('grupo', 40);
            $table->string('nombre', 80);
            $table->string('codigo', 4)->nullable()->index();
            $table->jsonb('respuestas');
            $table->unsignedSmallInteger('aciertos');
            $table->unsignedSmallInteger('total');
            $table->timestamps();
            $table->index(['grupo', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluaciones_bootcamp');
    }
};
