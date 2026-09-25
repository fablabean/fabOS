<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quién entra en el turno de los proyectos (§11).
 *
 * Una solicitud de la web nacía sin responsable y alguien la asignaba a mano
 * después. El resultado, contado: de ciento tres proyectos, cincuenta y dos
 * eran de la misma persona y los ocho últimos seguidos también. No es que
 * nadie repartiera; es que repartir a mano, cada vez, con la lista delante,
 * acaba siempre en quien primero viene a la cabeza.
 *
 * El interruptor va en la persona y no en el rol: quién recibe proyectos no
 * se deduce de ser administrador —hay administradores que no llevan
 * proyectos, y practicantes que sí— y tenerlo escrito en el código obligaría
 * a desplegar cada vez que alguien entra o sale del turno.
 *
 * Nace apagado para todos. El turno vacío no asigna a nadie, que es
 * exactamente lo que hay hoy: la coordinación marca a quien corresponda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('recibe_proyectos')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('recibe_proyectos');
        });
    }
};
