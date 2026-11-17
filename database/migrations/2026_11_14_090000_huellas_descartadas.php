<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «No es una persona»: huellas de cosas que el PGX tomó por un rostro (manos,
 * un afiche). Sin foto: solo el número, para no volver a contarlas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('huellas_descartadas', function (Blueprint $table) {
            $table->id();
            $table->json('huella');
            // La última vez que algo se le pareció: si deja de aparecer, se olvida.
            $table->timestamp('ultima_vez')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('huellas_descartadas');
    }
};
