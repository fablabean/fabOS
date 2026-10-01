<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recorridos gamificados: grupos grandes que conocen el laboratorio jugando.
 *
 * Un **circuito** es la plantilla: estaciones en orden, cada una con su pista
 * (la que el líder ve en las gafas), su QR pegado en el lugar y su prueba. Una
 * **partida** es una vez que se juega, casi siempre colgada de una reserva de
 * recorrido. Los **equipos** avanzan estación por estación, y cada paso queda
 * con su hora en el **avance**: de ahí salen los tiempos y el tablero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recorrido_circuitos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('recorrido_estaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('circuito_id')->constrained('recorrido_circuitos')->cascadeOnDelete();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->string('nombre');                       // para el equipo del laboratorio
            $table->string('lugar')->nullable();            // dónde va pegado el QR
            $table->string('codigo', 12)->unique();         // lo que lleva el QR

            // Lo que ve el líder en las gafas.
            $table->text('pista');
            $table->string('pista_imagen')->nullable();

            // La prueba que sale al escanear.
            $table->text('pregunta');
            $table->string('pregunta_imagen')->nullable();
            $table->string('tipo_respuesta', 20);           // texto · opcion · ubicar · enlazar
            $table->json('datos_respuesta');

            $table->timestamps();
        });

        Schema::create('recorrido_partidas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('circuito_id')->constrained('recorrido_circuitos')->restrictOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained('reservations')->nullOnDelete();
            $table->string('nombre');
            $table->string('codigo', 12)->unique();         // el tablero público
            $table->string('estado', 20)->default('preparada');
            $table->unsignedInteger('penalizacion_segundos')->default(30);
            $table->boolean('rotar_orden')->default(true);
            $table->timestampTz('iniciada_at')->nullable();
            $table->timestampTz('terminada_at')->nullable();
            $table->timestamps();
        });

        Schema::create('recorrido_equipos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partida_id')->constrained('recorrido_partidas')->cascadeOnDelete();
            $table->string('nombre');
            $table->string('color', 20)->default('#0D6E63');
            $table->unsignedSmallInteger('posicion')->default(0);

            // Para entrar desde el celular y para emparejar las gafas.
            $table->string('codigo', 8)->unique();
            $table->string('token', 40)->unique();
            $table->string('visor_token_hash', 64)->nullable()->unique();
            $table->timestampTz('visor_visto_at')->nullable();

            // Dónde va: el orden de sus estaciones, la etapa y en qué paso.
            $table->json('orden')->nullable();
            $table->unsignedSmallInteger('etapa')->default(0);
            $table->string('estado', 20)->default('esperando');
            $table->json('secuencia')->nullable();
            $table->unsignedInteger('fallos')->default(0);
            $table->unsignedInteger('penalizacion')->default(0);   // segundos
            $table->timestampTz('terminado_at')->nullable();
            $table->timestamps();
        });

        Schema::create('recorrido_integrantes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipo_id')->constrained('recorrido_equipos')->cascadeOnDelete();
            $table->string('nombre');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('recorrido_avances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipo_id')->constrained('recorrido_equipos')->cascadeOnDelete();
            $table->unsignedSmallInteger('etapa');
            $table->foreignId('estacion_id')->constrained('recorrido_estaciones')->cascadeOnDelete();
            $table->foreignId('lider_id')->nullable()->constrained('recorrido_integrantes')->nullOnDelete();
            $table->timestampTz('pista_at')->nullable();
            $table->timestampTz('qr_at')->nullable();
            $table->timestampTz('resuelta_at')->nullable();
            $table->timestampTz('secuencia_at')->nullable();
            $table->unsignedInteger('fallos_prueba')->default(0);
            $table->unsignedInteger('fallos_secuencia')->default(0);
            $table->timestamps();

            $table->unique(['equipo_id', 'etapa']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recorrido_avances');
        Schema::dropIfExists('recorrido_integrantes');
        Schema::dropIfExists('recorrido_equipos');
        Schema::dropIfExists('recorrido_partidas');
        Schema::dropIfExists('recorrido_estaciones');
        Schema::dropIfExists('recorrido_circuitos');
    }
};
