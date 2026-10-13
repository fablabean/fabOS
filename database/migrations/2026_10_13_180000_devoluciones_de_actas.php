<?php

use App\Models\NotificationTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La devolución de lo que salió con un acta (§8).
 *
 * El acta decía que un equipo se había prestado, pero no que había vuelto ni
 * cómo: quedaba abierta para siempre, y si volvía golpeado no había dónde
 * decirlo. Cada devolución es su propio documento —vuelven dos de tres
 * equipos hoy y el tercero la semana que viene—, con el estado en que volvió
 * cada uno y la firma de quien lo recibe en el laboratorio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acta_devoluciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('acta_id')->constrained('actas')->cascadeOnDelete();
            $table->string('code', 20)->unique();
            $table->date('fecha');
            // Quien lo tenía y lo trae de vuelta: la copia del acta.
            $table->string('devuelve_nombre', 160);
            $table->string('devuelve_cedula', 40)->nullable();
            // Quien lo recibe en el laboratorio, que es quien firma.
            $table->foreignId('recibe_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recibe_nombre', 160);
            $table->string('recibe_cedula', 40)->nullable();
            $table->text('observaciones')->nullable();
            $table->string('firma_path')->nullable();
            $table->timestamp('firmo_at')->nullable();
            $table->string('pdf_path')->nullable();
            $table->timestamp('enviada_a_planta_at')->nullable();
            $table->string('enviada_a_planta_a', 160)->nullable();
            $table->foreignId('enviada_a_planta_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('acta_items', function (Blueprint $table) {
            $table->foreignId('devolucion_id')->nullable()->constrained('acta_devoluciones')->nullOnDelete();
            // B, R o M, como al entregarlo: para ver si volvió igual.
            $table->string('estado_devolucion', 1)->nullable();
            $table->string('nota_devolucion', 300)->nullable();
        });

        NotificationTemplate::firstOrCreate(['key' => 'acta.devolucion_planta'], [
            'name'         => 'Devolución de activos, para Planta Física',
            'description'  => 'A Infraestructura y Planta Física, cuando regresa un activo que había salido de la Universidad, con el formato de devolución en PDF.',
            'is_essential' => true,
            'is_active'    => true,
            'subject'      => 'Devolución de activos · {codigo} (acta {acta})',
            'variables'    => ['nombre_pila', 'codigo', 'acta', 'fecha', 'devuelve', 'activos', 'pendientes', 'quien', 'laboratorio'],
            'body'         => <<<'TXT'
                Hola {nombre_pila},

                Te informo que regresaron al laboratorio activos que habían salido con el
                acta {acta}. Adjunto el formato de devolución {codigo}.

                Fecha de devolución: {fecha}
                Devuelve: {devuelve}

                {activos}

                {pendientes}

                — {quien}, {laboratorio}
                TXT,
        ]);
    }

    public function down(): void
    {
        NotificationTemplate::where('key', 'acta.devolucion_planta')->delete();

        Schema::table('acta_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('devolucion_id');
            $table->dropColumn(['estado_devolucion', 'nota_devolucion']);
        });

        Schema::dropIfExists('acta_devoluciones');
    }
};
