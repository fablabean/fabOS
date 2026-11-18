<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pantallas e-paper de los salones (IoT).
 *
 * Cada pantalla (ESP32 con tinta electrónica de 400×300) está en un salón y
 * muestra su agenda: qué actividad hay, quién la dirige, a qué hora, y un QR.
 * La pantalla no arma nada: fabOS dibuja la imagen y ella la descarga cuando
 * se lo pide alguien con el botón o cuando el servidor le dijo que despertara.
 *
 * El QR lleva a la página del salón: ahí quien dirige y quien asiste marcan su
 * llegada (con su cuenta) y cualquiera reporta una novedad del espacio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pantallas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('salon', 40);
            $table->string('ubicacion')->nullable();
            // A quién le llegan las novedades del salón; vacío: el correo general.
            $table->string('correos_novedades')->nullable();
            // Lo que va en el QR: la página pública del salón.
            $table->string('codigo', 16)->unique();
            $table->string('clave_hash', 64)->nullable()->unique();
            $table->boolean('activa')->default(true);
            // Lo que contó la pantalla la última vez que preguntó.
            $table->timestamp('visto_at')->nullable();
            $table->unsignedTinyInteger('bateria_pct')->nullable();
            $table->unsignedSmallInteger('bateria_mv')->nullable();
            $table->boolean('cargando')->nullable();
            $table->smallInteger('rssi')->nullable();
            $table->string('firmware', 40)->nullable();
            $table->string('motivo', 20)->nullable();
            $table->unsignedInteger('despertar_en')->nullable();
            $table->string('version_mostrada', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('pantalla_actividades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pantalla_id')->constrained('pantallas')->cascadeOnDelete();
            $table->string('titulo');
            $table->string('responsable')->nullable();
            $table->string('imagen_path')->nullable();
            $table->timestamp('inicia_at');
            $table->unsignedSmallInteger('minutos');
            $table->string('nota')->nullable();
            $table->timestamps();

            $table->index(['pantalla_id', 'inicia_at']);
        });

        Schema::create('pantalla_llegadas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actividad_id')->constrained('pantalla_actividades')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('rol', 20);
            $table->timestamps();

            $table->unique(['actividad_id', 'user_id']);
        });

        Schema::create('pantalla_novedades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pantalla_id')->constrained('pantallas')->cascadeOnDelete();
            $table->foreignId('actividad_id')->nullable()->constrained('pantalla_actividades')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('descripcion');
            $table->string('foto_path')->nullable();
            $table->string('contacto')->nullable();
            $table->timestamp('resuelta_at')->nullable();
            $table->foreignId('resuelta_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pantalla_novedades');
        Schema::dropIfExists('pantalla_llegadas');
        Schema::dropIfExists('pantalla_actividades');
        Schema::dropIfExists('pantallas');
    }
};
