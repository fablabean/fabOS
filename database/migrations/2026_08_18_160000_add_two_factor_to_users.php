<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Segundo factor para el backoffice (§16).
 *
 * El ingreso por código al correo hereda la seguridad de la bandeja de entrada:
 * suficiente para reservar una impresora, insuficiente para emitir FabCoins o
 * cambiar permisos. Quien administra necesita algo que no viva en el correo.
 *
 * El secreto va cifrado: un volcado de la base de datos no debe alcanzar para
 * generar códigos válidos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('remember_token');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestampTz('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
    }
};
