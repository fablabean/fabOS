<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La carpeta multimedia de las páginas.
 *
 * Archivos sueltos —un STL, una foto, un video, un JSON— que las animaciones
 * HTML de las páginas cargan por su dirección. La dirección sale del `codigo`
 * y no del archivo: si se reemplaza el STL por una versión nueva, el HTML que
 * lo usa no hay que tocarlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archivos_multimedia', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 16)->unique();
            $table->string('nombre');
            $table->string('archivo');
            $table->string('nombre_original')->nullable();
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('tamano')->default(0);
            $table->text('nota')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archivos_multimedia');
    }
};
