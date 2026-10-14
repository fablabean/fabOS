<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anuncios favoritos (§3).
 *
 * Algunos anuncios no son para la portada sino para el laboratorio: «cerramos
 * en diez minutos», «se va a apagar la cortadora». Quien está en recepción los
 * lanza varias veces al día, y entrar a la lista cada vez es demasiado. Los
 * favoritos salen en la barra de arriba del panel, a un clic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('anuncios', function (Blueprint $table) {
            $table->boolean('favorito')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('anuncios', function (Blueprint $table) {
            $table->dropColumn('favorito');
        });
    }
};
