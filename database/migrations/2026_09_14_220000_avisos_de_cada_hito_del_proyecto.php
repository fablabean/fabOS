<?php

use App\Models\NotificationTemplate;
use Illuminate\Database\Migrations\Migration;

/**
 * Avisar a quien pidió el proyecto cuando pasa algo con él (§11).
 *
 * El aviso de cierre existía, pero solo salía desde dos botones del listado.
 * Un proyecto se podía cerrar en silencio por cuatro caminos más —editando el
 * estado en la ficha, editando la etapa, descartándolo, o subiendo el informe
 * final, que lo cierra solo—, y entonces lo fabricado se quedaba en un estante
 * esperando a alguien que no sabía que tenía que venir.
 *
 * El aviso se mudó al servicio, donde de verdad cambia la etapa. Y con él
 * hacen falta tres textos que no existían: cuando el proyecto entra en máquina,
 * cuando se pausa y cuando se descarta.
 *
 * **Los tres son silenciables** (`is_essential = false`), al revés que el de
 * cierre: que tu proyecto empezó a fabricarse es bueno saberlo, pero no es como
 * enterarse de que ya puedes pasar a recogerlo. Un correo que no se puede
 * apagar acaba en la carpeta de spam junto con los que sí importaban.
 *
 * Se siembran aquí y no en el seeder porque **el despliegue migra pero no
 * siembra**: una plantilla que solo viviera en el seeder no existiría en
 * producción, y el aviso se registraría como «omitido: la plantilla no existe».
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            NotificationTemplate::firstOrCreate(['key' => 'proyecto.en_ejecucion'], [
                'name'         => 'Tu proyecto entró en producción',
                'description'  => 'A quien pidió el proyecto y a quien lo lidera, cuando pasa a la etapa de ejecución.',
                'is_essential' => false,
                'is_active'    => true,
                'subject'      => 'Empezamos a fabricar {proyecto} ({codigo})',
                'variables'    => ['nombre_pila', 'proyecto', 'codigo', 'mensaje', 'enlace', 'quien', 'laboratorio'],
                'body'         => <<<'TXT'
                    Hola {nombre_pila},

                    {mensaje}

                    Puedes seguirlo aquí:

                    {enlace}

                    — {quien}, {laboratorio}
                    TXT,
            ]);

            NotificationTemplate::firstOrCreate(['key' => 'proyecto.pausado'], [
                'name'         => 'Tu proyecto quedó en pausa',
                'description'  => 'A quien pidió el proyecto y a quien lo lidera, cuando se pausa, con el motivo.',
                'is_essential' => false,
                'is_active'    => true,
                'subject'      => '{proyecto} ({codigo}) queda en pausa',
                'variables'    => ['nombre_pila', 'proyecto', 'codigo', 'mensaje', 'enlace', 'quien', 'laboratorio'],
                'body'         => <<<'TXT'
                    Hola {nombre_pila},

                    Tu proyecto «{proyecto}» queda en pausa por ahora. El motivo:

                    {mensaje}

                    No está cancelado: lo retomamos en cuanto se resuelva. Si tienes dudas o
                    algo cambió de tu lado, respóndenos por aquí:

                    {enlace}

                    — {quien}, {laboratorio}
                    TXT,
            ]);

            NotificationTemplate::firstOrCreate(['key' => 'proyecto.descartado'], [
                'name'         => 'Tu proyecto no sigue adelante',
                'description'  => 'A quien pidió el proyecto y a quien lo lidera, cuando se descarta o se da por perdido, con el motivo.',
                'is_essential' => false,
                'is_active'    => true,
                'subject'      => '{proyecto} ({codigo}) no sigue adelante',
                'variables'    => ['nombre_pila', 'proyecto', 'codigo', 'mensaje', 'enlace', 'quien', 'laboratorio'],
                'body'         => <<<'TXT'
                    Hola {nombre_pila},

                    Tu proyecto «{proyecto}» no va a seguir adelante. El motivo:

                    {mensaje}

                    Si crees que podemos retomarlo de otra forma, escríbenos: a veces lo que
                    no cabe de una manera sí cabe de otra.

                    {enlace}

                    — {quien}, {laboratorio}
                    TXT,
            ]);
        } catch (\Throwable) {
            // Si la tabla de plantillas cambió de forma, los avisos se siembran
            // después con el seeder. El cambio de etapa no depende de ellos: un
            // correo que no sale queda anotado en la bitácora y ya.
        }
    }

    public function down(): void
    {
        NotificationTemplate::whereIn('key', [
            'proyecto.en_ejecucion',
            'proyecto.pausado',
            'proyecto.descartado',
        ])->delete();
    }
};
