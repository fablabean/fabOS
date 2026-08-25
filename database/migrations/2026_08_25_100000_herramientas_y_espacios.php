<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dónde vive cada herramienta, y si puede salir de ahí (§7).
 *
 * El uso normal del laboratorio no es reservar una herramienta suelta: es
 * reservar un espacio y, dentro de él, tomar las herramientas que hagan falta.
 *
 * Y muchas **no pueden salir de su sitio**. Un juego de llaves del taller vive
 * en el taller: prestarlo a otra sala significa que quien trabaja allí se queda
 * sin él, y nadie se entera hasta que lo busca. Por eso `puede_salir` nace en
 * `false`: lo seguro es que se quede donde está, y el permiso para moverla se
 * concede a propósito.
 *
 * Los activos fijos —las máquinas— no cambian: se reservan una por una, porque
 * no se mueven a ninguna parte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            // El espacio donde se usa. Distinto de `location_id`, que es el
            // mueble donde se guarda: una herramienta puede vivir en el rack A
            // y usarse en toda la sala.
            $table->foreignId('space_id')->nullable()->after('location_id')
                ->constrained()->nullOnDelete();

            $table->boolean('puede_salir')->default(false)->after('space_id');
        });

        Schema::table('reservations', function (Blueprint $table) {
            // Cuánta gente. Solo tiene sentido al reservar un espacio: una
            // máquina la usa quien la reservó.
            $table->unsignedSmallInteger('participants')->nullable()->after('purpose');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('space_id');
            $table->dropColumn('puede_salir');
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('participants');
        });
    }
};
