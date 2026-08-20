<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El carne digital no siempre trae numero de documento: en los carnes de
 * colaborador viene vacio. Sin un identificador estable no se puede saber a
 * quien pertenece, asi que se guarda el nombre normalizado del carne como
 * vinculo explicito entre una cuenta y su carne.
 *
 * Se vincula una vez, autenticado por otro medio; despues el QR sirve de atajo.
 * La identidad la aporta la cuenta vinculada, no lo que diga el HTML.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('carnet_subject')->nullable()->unique()->after('external_id');
            $table->timestamp('carnet_linked_at')->nullable()->after('carnet_subject');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['carnet_subject', 'carnet_linked_at']);
        });
    }
};
