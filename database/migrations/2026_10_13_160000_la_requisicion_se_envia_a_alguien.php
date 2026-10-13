<?php

use App\Models\NotificationTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Enviar» una solicitud de compra, de verdad (§13).
 *
 * El botón marcaba la solicitud como enviada y le ponía fecha, pero no se la
 * mandaba a nadie: quien la esperaba en compras no se enteraba. Ahora sale por
 * correo, con la requisición en PDF y la cotización, y queda anotado a quién.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->string('sent_to_email', 160)->nullable();
            $table->string('sent_to_name', 160)->nullable();
            $table->timestamp('sent_to_at')->nullable();
        });

        NotificationTemplate::firstOrCreate(['key' => 'compra.requisicion'], [
            'name'         => 'Requisición de compra, a quien la tramita',
            'description'  => 'A la persona de compras, cuando se envía una solicitud: con la requisición en PDF, la cotización si la hay, y el enlace para verla.',
            'is_essential' => true,
            'is_active'    => true,
            'subject'      => 'Requisición de compra {codigo} · {laboratorio}',
            'variables'    => ['nombre_pila', 'codigo', 'para_que', 'total', 'proveedor', 'lineas', 'adjuntos', 'enlace', 'quien', 'laboratorio'],
            'body'         => <<<'TXT'
                Hola {nombre_pila},

                Te envío la requisición de compra {codigo} de {laboratorio}.

                Para qué: {para_que}
                Total estimado: {total}
                Proveedor: {proveedor}

                {lineas}

                {adjuntos}

                También puedes verla y descargarla aquí:

                {enlace}

                — {quien}, {laboratorio}
                TXT,
        ]);
    }

    public function down(): void
    {
        NotificationTemplate::where('key', 'compra.requisicion')->delete();

        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropColumn(['sent_to_email', 'sent_to_name', 'sent_to_at']);
        });
    }
};
