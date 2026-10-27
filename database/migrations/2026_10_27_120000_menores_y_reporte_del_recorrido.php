<?php

use App\Models\NotificationTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dos cosas del recorrido que piden quienes lo traen.
 *
 * Los **menores de edad**: a un menor no se le escribe sin la autorización
 * de sus padres (Ley 1581 de 2012), y esa no la tenemos. Se marca antes del
 * correo y entonces no se guarda correo.
 *
 * El **reporte de la actividad**: quien solicitó el recorrido —casi siempre
 * un profesor— recibe un enlace con el resumen de todo: equipos, quiénes
 * jugaron, puestos y tiempos. Sin correos de nadie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recorrido_integrantes', function (Blueprint $table) {
            $table->boolean('menor_de_edad')->default(false)->after('nombre');
        });

        Schema::table('recorrido_partidas', function (Blueprint $table) {
            $table->string('reporte_token', 40)->nullable()->unique()->after('codigo');
            $table->string('reporte_enviado_a', 160)->nullable();
            $table->timestampTz('reporte_enviado_at')->nullable();
        });

        NotificationTemplate::firstOrCreate(['key' => 'recorrido.reporte'], [
            'name'         => 'Reporte del recorrido, a quien lo solicitó',
            'description'  => 'A quien trajo al grupo, normalmente un profesor: el enlace con el resumen del recorrido gamificado, con equipos, participantes, puestos y tiempos.',
            'is_essential' => true,
            'is_active'    => true,
            'subject'      => 'Así les fue en el recorrido · {laboratorio}',
            'variables'    => ['nombre_pila', 'partida', 'fecha', 'equipos', 'participantes', 'enlace', 'laboratorio'],
            'body'         => <<<'TXT'
                Hola {nombre_pila},

                Gracias por traer a su grupo a {laboratorio}. Este es el reporte del recorrido «{partida}» ({fecha}): {equipos} y {participantes}.

                Ahí están los equipos, quiénes jugaron, el puesto y el tiempo de cada uno:

                {enlace}

                Se puede imprimir o guardar en PDF desde la misma página.

                — {laboratorio}
                TXT,
        ]);
    }

    public function down(): void
    {
        NotificationTemplate::where('key', 'recorrido.reporte')->delete();

        Schema::table('recorrido_partidas', function (Blueprint $table) {
            $table->dropUnique(['reporte_token']);
            $table->dropColumn(['reporte_token', 'reporte_enviado_a', 'reporte_enviado_at']);
        });

        Schema::table('recorrido_integrantes', fn (Blueprint $table) => $table->dropColumn('menor_de_edad'));
    }
};
