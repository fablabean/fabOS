<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El espacio es el sitio; la ubicación, el mueble que hay dentro (§7).
 *
 * El modelo estaba al revés en dos puntos, y los dos venían de confundir tres
 * cosas que en el laboratorio son distintas:
 *
 *   **Espacio**    el sitio físico: una sala, un taller.
 *   **Ubicación**  el mueble donde se guarda algo: un rack, una mesa, un
 *                  gabinete. Está DENTRO de un espacio.
 *   **Área**       la disciplina: impresión 3D, corte láser. Normalmente ocupa
 *                  un espacio, pero puede repartirse entre varios.
 *
 * Antes, un espacio «pertenecía» a una ubicación —como si una sala estuviera
 * dentro de un armario— y solo podía tener un área, cuando la realidad es que
 * un área puede repartirse y un espacio puede albergar varias.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Un área puede estar en varios espacios, y un espacio albergar
        //    varias áreas. Es poco frecuente, pero pasa.
        Schema::create('area_space', function (Blueprint $table) {
            $table->id();
            $table->foreignId('area_id')->constrained()->cascadeOnDelete();
            $table->foreignId('space_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['area_id', 'space_id']);
        });

        // Lo que ya había: cada espacio tenía un área. Se conserva.
        DB::table('spaces')->whereNotNull('area_id')->orderBy('id')->each(function ($espacio) {
            DB::table('area_space')->insert([
                'area_id'    => $espacio->area_id,
                'space_id'   => $espacio->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        // 2. La ubicación está dentro del espacio, no al revés.
        Schema::table('locations', function (Blueprint $table) {
            $table->foreignId('space_id')->nullable()->after('parent_id')
                ->constrained()->nullOnDelete();
        });

        Schema::table('spaces', function (Blueprint $table) {
            $table->dropConstrainedForeignId('area_id');
            $table->dropConstrainedForeignId('location_id');
        });
    }

    public function down(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('space_id');
        });

        Schema::dropIfExists('area_space');
    }
};
