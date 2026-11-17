<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La huella del cuerpo (silueta y ropa) de cada persona, solo del día: la
 * ropa cambia de un día a otro, así que sirve para reconocerla de espaldas o
 * desempatar una cara dudosa ese mismo día. Sin foto; en la noche se borra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuerpos_del_dia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('persona_id')->constrained('personas_anonimas')->cascadeOnDelete();
            $table->date('fecha')->index();
            $table->json('huella');
            $table->json('color')->nullable();
            $table->unsignedTinyInteger('camara');
            $table->timestamp('vista_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cuerpos_del_dia');
    }
};
