<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Horas incluidas a la semana para quien tiene certifab (§12).
 *
 * Una impresora FDM se reserva por seis, ocho, diez horas: el trabajo largo
 * es lo normal, no la excepción. Cobrar esas horas a quien ya demostró que
 * sabe usarla convierte el certifab en un peaje, y lo que se quiere es lo
 * contrario: que habilitarse sirva para usar el laboratorio.
 *
 * **Es un cupo semanal, no un descuento por reserva.** Las primeras N horas
 * de cada reserva gratis habrían invitado a encadenar reservas de N horas y
 * no pagar nunca; un cupo por semana se acaba, y lo que pasa de ahí se cobra
 * con la tarifa de siempre.
 *
 * **Vive en la tarifa, no en el código.** Es un número más de la tarifa de la
 * familia —hoy, 8 horas para FDM—, y se edita en Finanzas → Tarifas como el
 * precio por hora. Hereda como todo lo demás: equipo → familia → área.
 *
 * **Solo cuenta para quien tiene certifab vigente** sobre el equipo o su
 * familia. Quien reserva acompañado porque todavía no se habilitó, o la pieza
 * que produce el equipo para un estudiante, pagan desde el primer minuto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rate_cards', function (Blueprint $table) {
            // Minutos incluidos por semana. Cero: no hay franquicia.
            $table->unsignedSmallInteger('included_weekly_minutes')->default(0)->after('rounding_minutes');
        });

        // La tarifa sembrada de FDM arranca con las 8 horas. Si alguien ya la
        // reescribio a mano, sigue siendo suya: solo se toca la sembrada.
        DB::table('rate_cards')->where('slug', 'familia-fdm')->update(['included_weekly_minutes' => 480]);
    }

    public function down(): void
    {
        Schema::table('rate_cards', function (Blueprint $table) {
            $table->dropColumn('included_weekly_minutes');
        });
    }
};
