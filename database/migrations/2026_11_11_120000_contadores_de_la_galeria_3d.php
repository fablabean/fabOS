<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuántas veces se vio, gustó y descargó cada figura de la Galería 3D.
 *
 * Una fila por figura (su slug, sea de ejemplo o subida desde el panel). Se
 * cuenta sin cuentas de usuario: una vista y un «me gusta» por sesión del
 * navegador, para que recargar la página no infle las cifras.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('galeria3d_contadores', function (Blueprint $table) {
            $table->string('slug', 80)->primary();
            $table->unsignedBigInteger('vistas')->default(0);
            $table->unsignedBigInteger('me_gusta')->default(0);
            $table->unsignedBigInteger('descargas')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('galeria3d_contadores');
    }
};
