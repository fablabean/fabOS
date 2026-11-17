<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El recorte de cada persona contada en el día.
 *
 * Es para que quien revisa el conteo la reconozca mirándola; el sistema no
 * sabe quién es. Vive lo mismo que la huella: se borra al cerrar el día.
 * `ancho_rostro` guarda el rostro más grande visto, para cambiar la foto por
 * una mejor cuando la persona se acerca a la cámara.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('huellas_del_dia', function (Blueprint $t) {
            $t->string('foto')->nullable();
            $t->unsignedSmallInteger('ancho_rostro')->default(0);
            $t->unsignedSmallInteger('camara')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('huellas_del_dia', function (Blueprint $t) {
            $t->dropColumn(['foto', 'ancho_rostro', 'camara']);
        });
    }
};
