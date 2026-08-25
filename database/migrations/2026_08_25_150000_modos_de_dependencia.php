<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué significa que un equipo dependa de otro (§7).
 *
 * Hasta ahora solo había un significado: «no se puede usar si aquello no está
 * operativo» —sin compresor no hay CNC—. Pero en el laboratorio conviven tres
 * relaciones distintas y tratarlas igual obliga a mentir en dos de ellas:
 *
 *   **operativo**  El otro tiene que estar sano, pero no se reserva. El
 *                  compresor lo comparte todo el mundo.
 *   **junto**      Se reservan a la vez, siempre. Unas gafas de realidad
 *                  virtual sin la sala donde están no sirven de nada.
 *   **opcional**   Se ofrece al reservar y decide quien reserva. Las mismas
 *                  gafas se piden con computador o sin él.
 *
 * `operativo` por defecto: es lo que significaban las cuatro filas que ya
 * existen, y cambiarles el sentido al migrar sería reescribir una decisión que
 * alguien tomó.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_dependencies', function (Blueprint $table) {
            $table->string('modo', 20)->default('operativo')->after('depends_on_asset_id');
        });

        // Y si reservar el equipo ocupa también su espacio.
        Schema::table('assets', function (Blueprint $table) {
            $table->boolean('reserva_con_espacio')->default(false)->after('puede_salir');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn('reserva_con_espacio');
        });

        Schema::table('asset_dependencies', function (Blueprint $table) {
            $table->dropColumn('modo');
        });
    }
};
