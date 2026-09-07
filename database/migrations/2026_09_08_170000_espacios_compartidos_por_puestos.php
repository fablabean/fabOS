<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Espacios que se comparten por puestos (§7).
 *
 * Una reserva de espacio tomaba la sala completa aunque fuera para una
 * persona: en la sala de computo, alguien reservaba un puesto de tres a
 * cuatro y los otros diecinueve quedaban «ocupados» para cualquiera que
 * llegara despues. Ese es el comportamiento correcto para el taller o la
 * sala de corte, donde una actividad no convive con otra; para una sala de
 * puestos, no.
 *
 * El espacio dice si se comparte. Si se comparte, cada reserva toma los
 * puestos que pide, y la sala se llena por aforo: la suma de lo reservado a
 * esa hora no puede pasar de lo que cabe. La restriccion de no solapamiento
 * deja fuera esas reservas, igual que ya dejaba fuera los recorridos; el
 * tope lo pone el servicio, contando puestos.
 */
return new class extends Migration
{
    private const BLOQUEANTES = ['confirmada', 'en_curso'];

    public function up(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            $table->boolean('shares_seats')->default(false)->after('es_todo');
        });

        Schema::table('reservations', function (Blueprint $table) {
            // Se copia a la reserva al crearla: la restriccion de la base no
            // puede mirar el espacio, y una sala que deje de compartirse no
            // debe convertir en choque lo que ya estaba reservado.
            $table->boolean('shares_seats')->default(false)->after('participants');
        });

        $bloqueantes = "'" . implode("','", self::BLOQUEANTES) . "'";

        DB::statement('ALTER TABLE reservations DROP CONSTRAINT IF EXISTS reservations_sin_traslape');
        DB::statement("
            ALTER TABLE reservations
            ADD CONSTRAINT reservations_sin_traslape
            EXCLUDE USING gist (
                reservable_type WITH =,
                reservable_id   WITH =,
                period          WITH &&
            )
            WHERE (status IN ({$bloqueantes}) AND mode <> 'recorrido' AND NOT shares_seats)
        ");
    }

    public function down(): void
    {
        $bloqueantes = "'" . implode("','", self::BLOQUEANTES) . "'";

        DB::statement('ALTER TABLE reservations DROP CONSTRAINT IF EXISTS reservations_sin_traslape');
        DB::statement("
            ALTER TABLE reservations
            ADD CONSTRAINT reservations_sin_traslape
            EXCLUDE USING gist (
                reservable_type WITH =,
                reservable_id   WITH =,
                period          WITH &&
            )
            WHERE (status IN ({$bloqueantes}) AND mode <> 'recorrido')
        ");

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('shares_seats');
        });

        Schema::table('spaces', function (Blueprint $table) {
            $table->dropColumn('shares_seats');
        });
    }
};
