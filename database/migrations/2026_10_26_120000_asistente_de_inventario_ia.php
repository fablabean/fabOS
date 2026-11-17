<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Activo IA: lo que se le preguntó y lo que se guardó por su propuesta.
 *
 * Dos tablas porque son dos cosas distintas. Una **consulta** es cada mensaje
 * que se le escribió al asistente, con la foto y lo que propuso: sirve para
 * medir cuánto acierta. Una **acción** es lo que una persona confirmó y quedó
 * escrito en el inventario, con el valor de antes y el de después: es el
 * historial que responde «¿quién cambió esto y por qué?».
 *
 * Que la IA propusiera algo no deja rastro en el inventario. Solo la
 * confirmación lo deja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultas_de_inventario_ia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('conversacion');
            $table->text('instruccion')->nullable();
            // En el disco privado: es una foto del laboratorio, no del sitio.
            $table->string('foto_path')->nullable();
            $table->text('respuesta')->nullable();
            $table->json('herramientas')->nullable();
            $table->json('propuestas')->nullable();
            $table->unsignedSmallInteger('duplicados_detectados')->default(0);
            $table->string('modelo')->nullable();
            $table->unsignedInteger('tokens_entrada')->default(0);
            $table->unsignedInteger('tokens_salida')->default(0);
            $table->string('estado', 20)->default('respondida');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['conversacion', 'id']);
            $table->index('created_at');
        });

        Schema::create('acciones_de_inventario_ia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consulta_id')->nullable()->constrained('consultas_de_inventario_ia')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('accion', 40);
            $table->nullableMorphs('registro');
            $table->json('antes')->nullable();
            $table->json('despues')->nullable();
            // Lo que propuso la IA, tal cual, y qué campos cambió la persona.
            $table->json('propuesta')->nullable();
            $table->json('corregidos')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acciones_de_inventario_ia');
        Schema::dropIfExists('consultas_de_inventario_ia');
    }
};
