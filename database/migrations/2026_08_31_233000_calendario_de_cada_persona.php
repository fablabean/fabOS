<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La direccion secreta del calendario de cada persona (§8).
 *
 * Outlook se suscribe a un calendario por URL, y esa URL la lee un programa,
 * no una persona: no hay sesion ni contrasena que meter. Asi que la direccion
 * TIENE que ser el secreto, y por eso es larga y aleatoria.
 *
 * Se genera cuando alguien la pide, no para todo el mundo: una direccion que
 * nadie usa es una puerta abierta que nadie vigila.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('calendar_token', 64)->nullable()->unique()->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('calendar_token');
        });
    }
};
