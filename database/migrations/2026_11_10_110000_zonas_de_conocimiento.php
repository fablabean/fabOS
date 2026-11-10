<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Las zonas de conocimiento: material de un tema —robótica, Fab Academy, lo
 * general del laboratorio— con su propio subdominio (robotica.fablabean.com)
 * y sus módulos, que hoy son presentaciones de una sola página.
 *
 * Nace con una zona y un módulo: «Conoce el FabLab», que ya existía como
 * página hecha a mano y desde ahora se cambia desde el panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zonas', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();      // el subdominio
            $table->string('nombre', 120);
            $table->text('descripcion')->nullable();
            $table->string('foto_path')->nullable();
            $table->boolean('publicada')->default(false);
            $table->unsignedInteger('orden')->default(0);
            $table->timestamps();
        });

        Schema::create('modulos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('zona_id')->constrained('zonas')->cascadeOnDelete();
            $table->string('slug', 80);
            $table->string('titulo', 160);
            $table->text('resumen')->nullable();
            // «html» hoy: un archivo hecho y subido. Las láminas armadas en el
            // panel vienen después y caben aquí sin otra tabla.
            $table->string('tipo', 20)->default('html');
            $table->string('html_path')->nullable();
            $table->string('portada_path')->nullable();
            $table->string('acceso', 20)->default('publico');   // publico · cuenta · inscritos
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->boolean('publicado')->default(false);
            $table->boolean('destacado')->default(false);
            $table->unsignedInteger('orden')->default(0);
            $table->timestamps();

            $table->unique(['zona_id', 'slug']);
        });

        // La primera: lo general del laboratorio, con la presentación que ya había.
        $ahora = now();
        $zona = DB::table('zonas')->insertGetId([
            'slug' => 'conocimiento', 'nombre' => 'Conocimiento general',
            'descripcion' => '<p>El material para conocer el laboratorio: qué es, cómo se trabaja y qué se puede hacer en él.</p>',
            'publicada' => true, 'orden' => 0, 'created_at' => $ahora, 'updated_at' => $ahora,
        ]);

        $origen = resource_path('presentaciones/conoce-el-fablab.html');
        $destino = 'zonas/conoce-el-fablab.html';

        if (is_file($origen)) {
            Storage::disk('local')->put($destino, file_get_contents($origen));
        }

        DB::table('modulos')->insert([
            'zona_id' => $zona, 'slug' => 'conoce-el-fablab', 'titulo' => 'Conoce el FabLab',
            'resumen' => 'La presentación del laboratorio: para quienes hablan con los aspirantes y para proyectar en una charla.',
            'tipo' => 'html', 'html_path' => is_file($origen) ? $destino : null,
            'acceso' => 'publico', 'publicado' => true, 'destacado' => true, 'orden' => 0,
            'created_at' => $ahora, 'updated_at' => $ahora,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('modulos');
        Schema::dropIfExists('zonas');
    }
};
