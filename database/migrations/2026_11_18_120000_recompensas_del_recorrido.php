<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mini juegos del recorrido: penalizaciones y recompensas que mandan las gafas.
 *
 * La penalización ya existía: cada fallo suma los segundos de la partida. La
 * recompensa es lo contrario, **resta** segundos al tiempo del equipo, y cada
 * partida dice cuántos. Cada una queda anotada como evento, con el mini juego
 * que la dio y el identificador que manda la app: si las gafas reintentan por
 * mala señal, el mismo evento no se cuenta dos veces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recorrido_partidas', function (Blueprint $table) {
            $table->unsignedInteger('recompensa_segundos')->default(30)->after('penalizacion_segundos');
        });

        Schema::table('recorrido_equipos', function (Blueprint $table) {
            $table->unsignedInteger('recompensas')->default(0)->after('penalizacion');
            $table->unsignedInteger('recompensa')->default(0)->after('recompensas');
        });

        Schema::create('recorrido_eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipo_id')->constrained('recorrido_equipos')->cascadeOnDelete();
            $table->string('tipo', 20);
            $table->unsignedInteger('segundos');
            $table->unsignedSmallInteger('etapa')->default(0);
            $table->string('juego', 60)->nullable();
            $table->string('evento_id', 64)->nullable();
            $table->timestamps();

            $table->unique(['equipo_id', 'evento_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recorrido_eventos');
        Schema::table('recorrido_equipos', fn (Blueprint $table) => $table->dropColumn(['recompensas', 'recompensa']));
        Schema::table('recorrido_partidas', fn (Blueprint $table) => $table->dropColumn('recompensa_segundos'));
    }
};
