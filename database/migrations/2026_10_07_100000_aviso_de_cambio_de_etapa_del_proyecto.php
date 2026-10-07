<?php

use App\Models\NotificationTemplate;
use Illuminate\Database\Migrations\Migration;

/**
 * Avisar de cada cambio de etapa, no solo de dos (§11).
 *
 * Solo salía correo al entrar en ejecución y al cerrar. Pero mover un proyecto
 * de «idea» a cualquier otra etapa *es* contestarle a quien lo pidió, y sin
 * correo esa respuesta no le llegaba a nadie ni quedaba en ninguna parte: la
 * lista seguía marcándolo como sin responder.
 *
 * Silenciable, como los otros de hito: es bueno saberlo, no es urgente.
 *
 * Se siembra aquí y no solo en el seeder porque el despliegue migra pero no
 * siembra.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            NotificationTemplate::firstOrCreate(['key' => 'proyecto.cambio_de_etapa'], [
                'name'         => 'Tu proyecto cambió de etapa',
                'description'  => 'A quien pidió el proyecto y a quien lo lidera, cuando alguien del equipo lo mueve de etapa. Entrar en ejecución y cerrar tienen su propio aviso.',
                'is_essential' => false,
                'is_active'    => true,
                'subject'      => '{proyecto} ({codigo}) pasó a {etapa}',
                'variables'    => ['nombre_pila', 'proyecto', 'codigo', 'etapa', 'etapa_anterior', 'mensaje', 'enlace', 'quien', 'laboratorio'],
                'body'         => <<<'TXT'
                    Hola {nombre_pila},

                    Tu proyecto «{proyecto}» pasó de «{etapa_anterior}» a «{etapa}».

                    {mensaje}

                    Puedes seguirlo y escribirnos aquí:

                    {enlace}

                    — {quien}, {laboratorio}
                    TXT,
            ]);
        } catch (\Throwable) {
            // Si la tabla de plantillas cambió de forma, el aviso se siembra
            // después con el seeder. El cambio de etapa no depende de él.
        }
    }

    public function down(): void
    {
        NotificationTemplate::where('key', 'proyecto.cambio_de_etapa')->delete();
    }
};
