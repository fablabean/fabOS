<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reconocer un aporte con FabCoins (§12, §21).
 *
 * Documentar lo que pasa en el laboratorio es trabajo, y hasta ahora era
 * trabajo gratis: se pedía subir la foto y no pasaba nada. Ahora un aporte
 * puede reconocerse con saldo.
 *
 * Se reconoce **a mano y una por una**, desde Comunicaciones. La alternativa
 * -abonar sola cada subida- premia por cantidad y no por valor: lo que se
 * acaba incentivando es subir, no documentar, y doscientas fotos borrosas
 * valdrían más que el video en que alguien explica cómo lo hizo. Además,
 * emitir moneda es un acto del laboratorio y por eso lleva firma, igual que la
 * dotación.
 *
 * Las tres columnas dicen las tres cosas que hay que poder responder seis
 * meses después: si se reconoció, cuánto, y quién lo decidió. El movimiento en
 * el libro apunta a la pieza, así que la cifra de aquí y la del libro se
 * pueden contrastar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contenidos', function (Blueprint $table) {
            $table->timestamp('recognized_at')->nullable()->after('rights_version');

            // Se guarda el importe y no solo la marca: el valor por defecto
            // cambia con el tiempo, y sin esto no habria forma de saber cuanto
            // se reconocio por un aporte de hace un año.
            $table->unsignedInteger('recognized_minor')->nullable()->after('recognized_at');

            $table->foreignId('recognized_by')
                ->nullable()
                ->after('recognized_minor')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contenidos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recognized_by');
            $table->dropColumn(['recognized_at', 'recognized_minor']);
        });
    }
};
