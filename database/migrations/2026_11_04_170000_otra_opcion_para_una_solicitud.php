<?php

use App\Models\NotificationTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proponer otra opción a quien pidió algo que no se puede tal cual: «solo una
 * hora», «mejor el jueves». Se le escribe desde la bandeja con las opciones
 * que el sistema sabe que sí caben, y la que elija queda reservada sola.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            // Lo que se le propuso: quién, qué mensaje, qué opciones y cuál tomó.
            $table->json('propuesta')->nullable()->after('status_reason');
        });

        NotificationTemplate::firstOrCreate(['key' => 'reserva.otra_opcion'], [
            'name'         => 'Otra opción para una solicitud',
            'description'  => 'A quien pidió una reserva que no se puede tal cual, cuando la coordinación le propone otras opciones desde la bandeja.',
            'is_essential' => true,
            'is_active'    => true,
            'subject'      => 'Tu solicitud de {recurso}: te proponemos otra opción',
            'variables'    => ['nombre_pila', 'recurso', 'pedida', 'mensaje', 'opciones', 'laboratorio'],
            'body'         => <<<'TXT'
                Hola {nombre_pila},

                Revisamos tu solicitud de {recurso} para el {pedida}.{mensaje}

                Estas opciones sí te las podemos confirmar. Abre la que te sirva y queda reservada:

                {opciones}

                Si ninguna te sirve, responde este correo y lo vemos.

                — {laboratorio}
                TXT,
        ]);
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('propuesta');
        });

        NotificationTemplate::where('key', 'reserva.otra_opcion')->delete();
    }
};
