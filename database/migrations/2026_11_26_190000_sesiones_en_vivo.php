<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que dejó cada sesión en vivo (votos, muro, tweets, agenda). Mientras la
 * clase corre todo vive en la caché, que se borra a los dos días; aquí queda
 * la copia para siempre, una fila por sala y día.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sesiones_en_vivo', function (Blueprint $table) {
            $table->id();
            $table->string('sala', 40);
            $table->date('fecha');
            $table->jsonb('datos');
            $table->timestamps();
            $table->unique(['sala', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sesiones_en_vivo');
    }
};
