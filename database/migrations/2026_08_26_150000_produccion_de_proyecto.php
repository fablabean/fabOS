<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Producir con una máquina para un proyecto (§10, §11).
 *
 * **Una producción es una reserva.** Podría parecer que merece su propia tabla
 * —tiene otro sentido: el laboratorio operando su propia máquina para un
 * encargo, no una persona practicando—, pero fabricar ocupa el equipo
 * exactamente igual. Si viviera aparte habría dos calendarios, y tarde o
 * temprano alguien reservaría la impresora para las tres de la tarde mientras
 * una pieza de seis horas sigue dentro.
 *
 * Como reserva, hereda gratis lo que ya está resuelto: la restricción EXCLUDE
 * de PostgreSQL impide el traslape, la disponibilidad la esconde de la lista, y
 * el costeo del proyecto la cuenta como tiempo de máquina. Solo hace falta
 * distinguirla para poder mirarla aparte y para no tratarla como lo que no es
 * —no se le cobra a nadie, no exige certifab, y una pieza puede estar
 * imprimiéndose de madrugada—.
 *
 * Y la lista de equipos que usa el proyecto: se declara para saber con qué se
 * cuenta antes de programar nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->boolean('is_production')->default(false)->after('mode');
            $table->index(['project_id', 'is_production']);
        });

        Schema::create('project_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();

            $table->string('note')->nullable();

            $table->timestamps();

            // El mismo equipo dos veces en el mismo proyecto no significa nada.
            $table->unique(['project_id', 'asset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_assets');

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'is_production']);
            $table->dropColumn('is_production');
        });
    }
};
