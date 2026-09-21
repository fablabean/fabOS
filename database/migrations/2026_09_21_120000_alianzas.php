<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alianzas: un proyecto con varias partes que aportan (§11).
 *
 * Hasta hoy un proyecto era bilateral: un cliente, un valor, un acuerdo que
 * dice «el laboratorio presta a {cliente}», un pago. Eso describe un encargo,
 * y el laboratorio quiere ser otra cosa además: el sitio donde se construyen
 * proyectos más grandes que un encargo. Alguien llega pidiendo ayuda para un
 * dron y, en vez de cobrarle, el laboratorio se alía: pone máquinas y horas,
 * quien llegó pone la idea y su trabajo, y otros —una empresa, un inversor,
 * otra facultad— pueden unirse con lo suyo.
 *
 * **Un proyecto tiene modalidad**: servicio, que es lo de siempre, o alianza.
 * Convertirlo es cambiar la modalidad: quien llegó con la idea pasa a ser el
 * primer aliado y el laboratorio aparece como otro. Nada de lo que ya existe
 * —embudo, tareas, costos, evidencias— cambia; cambia quién es parte y cómo
 * se habla del dinero.
 *
 * **Cada aliado aporta algo valorado**: dinero, horas, equipos, material o
 * conocimiento, en pesos, y una participación. Se registra así y no como
 * texto porque la pregunta que importa después —cuánto puso cada quien, de
 * quién es lo que se construyó— no se contesta con prosa.
 *
 * **Y se puede pedir entrar desde el sitio.** Una alianza abierta se muestra
 * en público con sus partes y lo que busca; quien quiera unirse deja quién es
 * y qué aportaría, y queda como propuesto hasta que la coordinación confirme.
 * Es lo que convierte al laboratorio en el nodo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // servicio · alianza
            $table->string('modality', 16)->default('servicio')->after('client_kind');

            // Si la alianza se muestra en el sitio y recibe propuestas de union.
            $table->boolean('alliance_open')->default(false)->after('modality');

            // Lo que la alianza busca, dicho para fuera: «un aliado en electronica
            // de potencia», «capital para el primer lote».
            $table->text('alliance_pitch')->nullable()->after('alliance_open');
        });

        Schema::create('project_partners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // laboratorio · iniciador · aliado · inversor
            $table->string('role', 16)->default('aliado');

            // ------------------------------------------------------ quien es
            $table->string('name', 160);
            $table->string('organization', 160)->nullable();
            $table->string('email', 160)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('document', 40)->nullable();       // NIT o cedula, para el acuerdo

            // ------------------------------------------------------ que pone
            // dinero · horas · equipos · material · conocimiento · otro
            $table->string('contribution_kind', 16)->default('otro');
            $table->bigInteger('contribution_value')->default(0);   // en pesos
            $table->text('contribution_note')->nullable();          // «200 horas de diseno», «la CNC grande»
            $table->decimal('share_percent', 5, 2)->nullable();     // participacion; nulo si no se ha repartido

            // ------------------------------------------------------ en que va
            // propuesto · confirmado · retirado
            $table->string('status', 16)->default('propuesto');
            $table->string('source', 12)->default('panel');         // panel · web
            $table->timestampTz('consent_at')->nullable();          // Ley 1581, si vino por el sitio
            $table->date('joined_on')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('confirmed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
            // Nadie se propone dos veces a la misma alianza: el segundo envio
            // corrige el primero.
            $table->unique(['project_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_partners');

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['modality', 'alliance_open', 'alliance_pitch']);
        });
    }
};
