<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Categoria = quien es la persona (§5). Determina tarifas, cupos y dotacion.
 * Es distinta del rol, que determina que puede hacer dentro del sistema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();          // estudiante, profesor, ...
            $table->string('name');
            $table->unsignedSmallInteger('position')->default(0);

            // Factor sobre tiempo, montaje y supervision. El material va a costo (§12).
            $table->decimal('rate_factor', 6, 3)->default(1);

            // Dotacion periodica de FabCoins, en unidades menores (1 FBC = 100).
            $table->bigInteger('allowance_minor')->default(0);

            // Limites de reserva
            $table->unsignedSmallInteger('max_hours_per_week')->nullable();
            $table->unsignedSmallInteger('max_days_ahead')->default(30);
            $table->boolean('can_reserve')->default(true);

            $table->boolean('is_institutional')->default(false); // pertenece a la EAN
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_categories');
    }
};
