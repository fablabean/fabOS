<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dispositivos IoT: cosas del laboratorio que se encienden por turnos.
 *
 * El primero es una consola de juego detrás de una Raspberry Pi con un relé:
 * quien se registra en el portal la enciende quince minutos. La Raspberry no
 * decide nada; pregunta cada pocos segundos si debe estar encendida, y la
 * respuesta sale de la fila de turnos.
 *
 * Un turno nace ya con su hora de inicio y de fin: empieza cuando acaba el
 * anterior. Así «¿está encendido?» es una consulta —¿hay un turno que cubra
 * este instante?— y no un estado que alguien tenga que acordarse de apagar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iot_dispositivos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->unsignedSmallInteger('minutos_turno')->default(15);
            $table->unsignedSmallInteger('minutos_por_fabcoin')->default(5);
            $table->boolean('activo')->default(true);

            // La llave de la Raspberry: se guarda solo su huella.
            $table->string('clave_hash', 64)->nullable()->unique();
            $table->timestampTz('visto_at')->nullable();
            $table->timestamps();
        });

        Schema::create('iot_turnos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispositivo_id')->constrained('iot_dispositivos')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nombre');                       // como sale en la pantalla
            $table->string('origen', 20);                   // registro · cuenta · fabcoin · manual
            $table->unsignedSmallInteger('minutos');
            $table->unsignedInteger('fabcoins')->default(0);
            $table->foreignId('invitado_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('empieza_at');
            $table->timestampTz('termina_at');
            $table->timestampTz('cancelado_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['dispositivo_id', 'termina_at']);
            $table->index(['dispositivo_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iot_turnos');
        Schema::dropIfExists('iot_dispositivos');
    }
};
