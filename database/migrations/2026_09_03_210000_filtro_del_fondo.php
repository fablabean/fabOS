<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un filtro sobre el fondo de la lámina (§3, portal público).
 *
 * Las fotos del laboratorio se toman con el teléfono, con la luz que haya, y
 * de fondo de un titular no siempre ayudan: una foto de colores vivos compite
 * con el texto. Un filtro —blanco y negro, sepia, desenfoque— la manda al
 * fondo sin tener que retocarla antes de subirla. Va en la lámina y no en el
 * fichero: se prueba y se cambia sin volver a subir nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            // ninguno | gris | sepia | frio | calido | desenfoque | contraste
            $table->string('filtro', 12)->default('ninguno')->after('velo');
        });
    }

    public function down(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->dropColumn('filtro');
        });
    }
};
