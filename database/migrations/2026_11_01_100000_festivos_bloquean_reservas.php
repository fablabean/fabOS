<?php

use App\Models\NotificationTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los festivos cierran las reservas.
 *
 * El laboratorio no abre los festivos, y el sistema no lo sabía: el lunes de
 * Todos los Santos de 2026 dejó reservar como un lunes cualquiera. Cada
 * festivo se vuelve un bloqueo de día completo (fabos:bloquear-festivos), y
 * `festivo` dice de qué día es: así no se crea dos veces, y si alguien lo
 * levanta porque ese festivo sí se abre, no vuelve a aparecer.
 *
 * Y el correo para quien ya había reservado en un festivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bloqueos_de_reservas', function (Blueprint $table) {
            $table->date('festivo')->nullable()->unique()->after('dias_completos');
        });

        NotificationTemplate::firstOrCreate(['key' => 'reserva.cancelada_por_festivo'], [
            'name'         => 'Reserva cancelada por caer en festivo',
            'description'  => 'A quien tenía una reserva en un día festivo, cuando el laboratorio la cancela porque ese día no abre.',
            'is_essential' => true,
            'is_active'    => true,
            'subject'      => 'Tu reserva del {fecha} se canceló: es festivo',
            'variables'    => ['nombre_pila', 'equipo', 'fecha', 'inicio', 'fin', 'festivo', 'enlace', 'laboratorio'],
            'body'         => <<<'TXT'
                Hola {nombre_pila},

                Tu reserva de {equipo} para el {fecha}, de {inicio} a {fin}, quedó cancelada: ese día es festivo ({festivo}) y el laboratorio no abre.

                El sistema la dejó pasar porque todavía no reconocía los festivos. Ya lo corregimos, y lamentamos el inconveniente.

                Por favor vuelve a reservar en otro día: {enlace}

                — {laboratorio}
                TXT,
        ]);
    }

    public function down(): void
    {
        NotificationTemplate::where('key', 'reserva.cancelada_por_festivo')->delete();

        Schema::table('bloqueos_de_reservas', function (Blueprint $table) {
            $table->dropUnique(['festivo']);
            $table->dropColumn('festivo');
        });
    }
};
