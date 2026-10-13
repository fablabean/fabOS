<?php

use App\Models\NotificationTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Una pausa que espera a quien pidió el proyecto, y se cierra sola si no
 * responde.
 *
 * «En pausa» vale para dos cosas distintas: el laboratorio espera algo suyo
 * —una pieza, una firma— o espera una respuesta de quien lo pidió. Solo la
 * segunda tiene plazo: se le recuerda, y si pasa el plazo sin que diga nada el
 * proyecto se cierra y se archiva. Por eso se marca al pausar, y se guarda
 * desde cuándo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->timestamp('paused_at')->nullable();
            $table->boolean('awaits_client')->default(false);
            $table->timestamp('pause_reminded_at')->nullable();
        });

        // Lo que ya estaba en pausa: desde su último cambio, que es lo más
        // cercano que hay. Ninguno queda marcado como esperando al cliente:
        // se pausaron sin esa regla, y cerrarlos por ella sería una sorpresa.
        DB::table('projects')->where('status', 'pausado')->update(['paused_at' => DB::raw('updated_at')]);

        NotificationTemplate::firstOrCreate(['key' => 'proyecto.pausa_por_vencer'], [
            'name'         => 'Tu proyecto se cerrará si no respondes',
            'description'  => 'A quien pidió el proyecto, unos días antes de que una pausa que espera su respuesta se cierre sola.',
            'is_essential' => false,
            'is_active'    => true,
            'subject'      => '{proyecto} ({codigo}): necesitamos tu respuesta antes del {fecha}',
            'variables'    => ['nombre_pila', 'proyecto', 'codigo', 'mensaje', 'fecha', 'enlace', 'quien', 'laboratorio'],
            'body'         => <<<'TXT'
                Hola {nombre_pila},

                Tu proyecto «{proyecto}» está en pausa esperando una respuesta tuya:

                {mensaje}

                Si no recibimos respuesta antes del {fecha}, lo cerraremos y quedará
                archivado. Para que siga adelante basta con que nos respondas aquí:

                {enlace}

                — {quien}, {laboratorio}
                TXT,
        ]);

        NotificationTemplate::firstOrCreate(['key' => 'proyecto.cerrado_sin_respuesta'], [
            'name'         => 'Tu proyecto se cerró por falta de respuesta',
            'description'  => 'A quien pidió el proyecto, cuando una pausa que esperaba su respuesta pasa el plazo y el proyecto se archiva.',
            'is_essential' => false,
            'is_active'    => true,
            'subject'      => '{proyecto} ({codigo}) quedó cerrado',
            'variables'    => ['nombre_pila', 'proyecto', 'codigo', 'mensaje', 'enlace', 'quien', 'laboratorio'],
            'body'         => <<<'TXT'
                Hola {nombre_pila},

                {mensaje}

                El proyecto queda archivado y ya no se puede retomar respondiendo a este
                correo. Si quieres continuar, pide un proyecto nuevo o acércate al
                laboratorio y habla con alguien del equipo:

                {enlace}

                — {quien}, {laboratorio}
                TXT,
        ]);
    }

    public function down(): void
    {
        NotificationTemplate::whereIn('key', ['proyecto.pausa_por_vencer', 'proyecto.cerrado_sin_respuesta'])->delete();

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['paused_at', 'awaits_client', 'pause_reminded_at']);
        });
    }
};
