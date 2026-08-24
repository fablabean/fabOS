<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quién asesora sobre cada equipo (§10).
 *
 * No se deriva de los certifabs a propósito. Estar certificada para usar una
 * máquina y ser quien atiende al público sobre ella son cosas distintas: media
 * plantilla puede estar certificada en la láser y aun así la asesoría la dan
 * dos personas concretas. Esto es una decisión de coordinación, y por eso se
 * declara.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_advisors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();

            // La responsable siempre gana el reparto: si hay una, las asesorías
            // de ese equipo son suyas y no entran en la rotación.
            $table->boolean('es_responsable')->default(false);

            $table->timestamps();

            // Una persona no puede figurar dos veces en el mismo equipo: si
            // pudiera, el reparto equitativo le daría el doble de turnos.
            $table->unique(['user_id', 'asset_id']);
            $table->index(['asset_id', 'es_responsable']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_advisors');
    }
};
