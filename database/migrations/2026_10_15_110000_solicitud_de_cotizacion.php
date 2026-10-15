<?php

use App\Models\NotificationTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedirle a la Universidad que cotice (§13).
 *
 * Hasta ahora una solicitud de compra llegaba con la cotización hecha: el
 * proveedor, los precios, el PDF. Cuando no la hay, compras cotiza con sus
 * proveedores, y para eso tiene su formato: el ADF-228-f5, «Términos de
 * referencia para solicitud de cotización». Lo que se pide ahí es otra cosa:
 * no precios, sino especificaciones técnicas, marca, quién supervisa y si hace
 * falta arte.
 *
 * La solicitud dice desde el principio de cuál de las dos es, y según eso se
 * envía y se descarga con un formato o con el otro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->string('modalidad', 20)->default('cotizada')->after('code');
            $table->string('supervisor', 160)->nullable()->after('notes');
            $table->string('process_area', 160)->nullable()->after('supervisor');
            $table->boolean('requires_art')->default(false)->after('process_area');
        });

        Schema::table('purchase_request_items', function (Blueprint $table) {
            $table->text('specs')->nullable()->after('description');
            $table->string('brand', 160)->nullable()->after('specs');
        });

        NotificationTemplate::firstOrCreate(['key' => 'compra.solicitud_cotizacion'], [
            'name'         => 'Solicitud de cotización, a compras',
            'description'  => 'A la persona de compras, cuando se envía una solicitud que pide cotizar: con el formato ADF-228-f5 en PDF y el enlace para verla.',
            'is_essential' => true,
            'is_active'    => true,
            'subject'      => 'Solicitud de cotización {codigo} · {laboratorio}',
            'variables'    => ['nombre_pila', 'codigo', 'para_que', 'lineas', 'enlace', 'quien', 'laboratorio'],
            'body'         => <<<'TXT'
                Hola {nombre_pila},

                Te envío la solicitud de cotización {codigo} de {laboratorio}, en el formato ADF-228-f5 (términos de referencia), para que se cotice con los proveedores de la Universidad.

                Para qué: {para_que}

                {lineas}

                Va adjunto el formato en PDF. También puedes verlo y descargarlo aquí:

                {enlace}

                — {quien}, {laboratorio}
                TXT,
        ]);
    }

    public function down(): void
    {
        NotificationTemplate::where('key', 'compra.solicitud_cotizacion')->delete();

        Schema::table('purchase_request_items', function (Blueprint $table) {
            $table->dropColumn(['specs', 'brand']);
        });

        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropColumn(['modalidad', 'supervisor', 'process_area', 'requires_art']);
        });
    }
};
