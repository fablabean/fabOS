<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El correo de quien juega un recorrido. No le crea cuenta: queda en el
 * informe de participantes para escribirle después, y solo si lo autorizó
 * (Ley 1581 de 2012).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recorrido_integrantes', function (Blueprint $table) {
            $table->string('correo', 160)->nullable()->index()->after('nombre');
            $table->timestampTz('autoriza_contacto_at')->nullable()->after('correo');
        });
    }

    public function down(): void
    {
        Schema::table('recorrido_integrantes', function (Blueprint $table) {
            $table->dropIndex(['correo']);
            $table->dropColumn(['correo', 'autoriza_contacto_at']);
        });
    }
};
