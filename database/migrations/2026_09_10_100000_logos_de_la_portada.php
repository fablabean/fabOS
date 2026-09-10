<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los logos de la portada (§3).
 *
 * Debajo del banner iba una franja de cifras —equipos, libres ahora, áreas—
 * que decía poco a quien llega por primera vez. Lo que sí dice algo es
 * quién respalda al laboratorio: la Universidad, la acreditación de calidad,
 * la Fab Foundation, la Fab Academy. Esos logos cambian con los años, y por
 * eso se administran desde el panel, como el banner, y no se escriben en el
 * código.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logos', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);

            $table->string('nombre');
            $table->string('imagen_path');
            $table->string('url')->nullable();

            $table->timestamps();

            $table->index(['is_active', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logos');
    }
};
