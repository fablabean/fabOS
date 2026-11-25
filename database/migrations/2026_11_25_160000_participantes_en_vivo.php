<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quien se conecta a una presentación en vivo (el bootcamp) escribe su nombre
 * y recibe un ID corto. Con ese ID le escribe al profesor después (LinkedIn)
 * y el profesor lo reconoce: por eso va en la base y no en la caché de la
 * sesión, que se borra a los dos días.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('participantes_en_vivo', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 4)->unique();
            // El grupo junta las salas de un mismo curso: el ID del día 2 sirve el día 3.
            $table->string('grupo', 40)->index();
            $table->string('nombre', 80);
            $table->string('sala', 40);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participantes_en_vivo');
    }
};
