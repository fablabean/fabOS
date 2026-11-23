<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los modelos de la Galería 3D que se suben desde el panel.
 *
 * El archivo original (un STL, un STEP de cientos de megas) no llega nunca al
 * servidor: el navegador de quien lo sube lo convierte y optimiza, y aquí solo
 * se guardan los cuatro archivos que necesita la galería, en
 * public/3d/subidos/<carpeta>. Del original queda el nombre y el formato, para
 * saber de dónde salió.
 *
 * `estadisticas` es lo mismo que escribe el taller para los modelos de
 * ejemplo: triángulos antes y después, pesos, medidas y los nombres de los
 * materiales (para los acabados).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modelos_3d', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('nombre', 120);
            $table->string('categoria', 40);
            $table->string('tecnica', 120)->nullable();
            $table->string('maquina', 160)->nullable();
            $table->string('material', 160)->nullable();
            $table->string('resumen', 300)->nullable();
            $table->text('descripcion')->nullable();
            $table->boolean('escala_fija')->default(false);
            $table->json('acabados')->nullable();

            $table->boolean('publicado')->default(false);
            $table->unsignedInteger('orden')->default(0);

            $table->string('carpeta')->nullable();
            $table->json('estadisticas')->nullable();
            $table->string('archivo_original')->nullable();
            $table->string('formato_original', 10)->nullable();

            $table->foreignId('subido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['publicado', 'orden']);
        });

        // La sección nueva del panel, con sus permisos: se le abre al
        // administrador (el superadmin la ve siempre). Lo mismo que hace
        // `fabos:accesos` en cada despliegue; aquí para que baste con migrar.
        app(\App\Services\Auth\MatrizDeAccesos::class)->sincronizar();
    }

    public function down(): void
    {
        Schema::dropIfExists('modelos_3d');
    }
};
