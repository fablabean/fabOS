<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reconocer un objeto por su foto (Activo IA).
 *
 * El servidor de IA del FabLab devuelve la **huella** de una foto: 1152
 * números que la resumen. Dos fotos del mismo objeto tienen huellas
 * parecidas. Aquí se guardan las fotos que una persona confirmó, con su
 * huella, y la próxima foto de ese objeto se reconoce comparándolas. No hay
 * que entrenar nada: cada confirmación es un ejemplo más.
 *
 * Los **vectores de texto** son lo mismo para el nombre de cada activo e
 * insumo: sirven para sugerir «se parece a…» cuando todavía no hay fotos con
 * qué comparar. Se recalculan solos cuando cambia el nombre, la marca o el
 * modelo.
 *
 * Las huellas van en JSON y se comparan en PHP: son unos cientos de vectores
 * y la base de datos no tiene pgvector.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fotos_de_inventario', function (Blueprint $table) {
            $table->id();
            $table->morphs('registro');
            $table->string('ruta');
            $table->json('huella');
            $table->string('modelo_huella')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('vectores_de_inventario', function (Blueprint $table) {
            $table->id();
            $table->morphs('registro');
            $table->string('huella_texto', 64);
            $table->json('vector');
            $table->string('modelo')->nullable();
            $table->timestamps();

            $table->unique(['registro_type', 'registro_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vectores_de_inventario');
        Schema::dropIfExists('fotos_de_inventario');
    }
};
