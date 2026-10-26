<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partidas por jornadas: el viernes de 8 a 4 se juega el recorrido de
 * Halloween y los equipos van llegando. Cada uno se inscribe y arranca su
 * propio reloj; al final, todos en el mismo tablero.
 *
 * Por eso el reloj pasa a ser del equipo (`iniciado_at`). En las partidas de
 * siempre, las simultáneas, es el de la partida para todos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recorrido_partidas', function (Blueprint $table) {
            $table->string('modo', 20)->default('simultanea')->after('estado');
            $table->json('jornadas')->nullable()->after('modo');    // [{inicio, fin}]
        });

        Schema::table('recorrido_equipos', function (Blueprint $table) {
            $table->timestampTz('iniciado_at')->nullable()->after('penalizacion');
        });
    }

    public function down(): void
    {
        Schema::table('recorrido_equipos', fn (Blueprint $table) => $table->dropColumn('iniciado_at'));
        Schema::table('recorrido_partidas', fn (Blueprint $table) => $table->dropColumn(['modo', 'jornadas']));
    }
};
