<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quién responde por cada área (§5).
 *
 * Determina quién puede otorgar certifabs de sus equipos: certificar no es un
 * trámite administrativo, lo hace quien conoce la máquina y la inducción.
 *
 * El suplente no es burocracia: sin él, las vacaciones del responsable dejan
 * un área entera sin quien habilite a nadie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('area_responsibles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('area_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_backup')->default(false);
            $table->timestamps();

            $table->unique(['area_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('area_responsibles');
    }
};
