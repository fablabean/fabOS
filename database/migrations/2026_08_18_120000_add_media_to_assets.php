<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Medios y texto público de los activos.
 *
 * El catálogo público es la vitrina del laboratorio: una máquina sin foto no
 * comunica nada. Se guarda UNA imagen representativa y un enlace de video;
 * si más adelante hacen falta galerías, se migra a una librería de medios.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('photo_path')->nullable()->after('qr_token');
            $table->string('video_url')->nullable()->after('photo_path');

            // Descripción para el público, distinta de las notas internas.
            $table->text('public_description')->nullable()->after('video_url');

            // Un equipo puede existir en el inventario sin salir en la vitrina.
            $table->boolean('is_public')->default(true)->after('public_description');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn(['photo_path', 'video_url', 'public_description', 'is_public']);
        });
    }
};
