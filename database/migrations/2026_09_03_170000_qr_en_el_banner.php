<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un QR en la lámina del banner (§3, portal público).
 *
 * La portada se proyecta en la pantalla del laboratorio y en el stand de una
 * feria, y ahí nadie hace clic: se saca el teléfono. Un QR en la lámina lleva
 * a quien mira directo a donde se quiere que llegue —un chat de WhatsApp, un
 * chat de Teams con una cuenta concreta, o cualquier dirección— sin que tenga
 * que teclear nada ni buscar el sitio después.
 *
 * WhatsApp y Teams van aparte de «una dirección» porque armar sus enlaces a
 * mano es justo lo que sale mal: el número sin indicativo, el correo en la
 * dirección equivocada. Aquí se escribe el número o la cuenta, y el enlace lo
 * arma el sistema. El mensaje opcional llega ya escrito en el chat: quien
 * escanea solo tiene que enviar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            // ninguno | whatsapp | teams | url
            $table->string('qr_tipo', 12)->default('ninguno')->after('accion2_url');
            // El número, la cuenta o la dirección, según el tipo.
            $table->string('qr_destino', 500)->nullable()->after('qr_tipo');
            // Lo que llega escrito en el chat. Solo WhatsApp y Teams.
            $table->string('qr_mensaje', 500)->nullable()->after('qr_destino');
            // Lo que dice debajo del QR.
            $table->string('qr_texto', 60)->nullable()->after('qr_mensaje');
        });
    }

    public function down(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->dropColumn(['qr_tipo', 'qr_destino', 'qr_mensaje', 'qr_texto']);
        });
    }
};
