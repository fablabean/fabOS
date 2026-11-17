<?php

use App\Models\NotificationTemplate;
use Illuminate\Database\Migrations\Migration;

/**
 * La invitación al registro oficial de Fab Academy.
 *
 * Con la cohorte publicada, a cada preinscrito que ya puede pasar se le
 * escribe una vez: que revise la página y que se registre en Fab Academy,
 * con el enlace al formulario en el mismo correo.
 */
return new class extends Migration
{
    public function up(): void
    {
        NotificationTemplate::firstOrCreate(['key' => 'curso.registro_oficial'], [
            'name'         => 'Invitación al registro oficial de Fab Academy',
            'description'  => 'A cada preinscrito que ya puede pasar, una sola vez, cuando el equipo lo envía desde la pestaña de preinscritos.',
            'is_essential' => true,
            'is_active'    => true,
            'subject'      => 'Fab Academy: ya puedes hacer tu registro oficial',
            'variables'    => ['nombre_pila', 'inicio', 'enlace', 'registro', 'condiciones', 'mensaje', 'laboratorio'],
            'body'         => <<<'TXT'
                Hola {nombre_pila},

                Fab Academy ya está publicada en {laboratorio} y empieza el {inicio}. Como estás en la lista de preinscritos, el siguiente paso es tu registro oficial en Fab Academy.

                1. Revisa la página del programa: cómo funciona, la inversión y las preguntas frecuentes.
                {enlace}

                2. Completa el formulario de registro de Fab Academy.
                {registro}

                Antes de enviarlo, ten en cuenta:
                {condiciones}{mensaje}

                Si tienes dudas, responde este correo.

                — {laboratorio}
                TXT,
        ]);
    }

    public function down(): void
    {
        NotificationTemplate::where('key', 'curso.registro_oficial')->delete();
    }
};
