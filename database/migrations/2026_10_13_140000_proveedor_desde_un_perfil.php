<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un proveedor puede venir de un perfil profesional (§5, §13).
 *
 * El perfil es la persona que puede trabajar con el laboratorio; cuando ya se
 * le compra, es también un proveedor. Se enlazan para no anotarla dos veces y
 * para saber, desde cualquiera de los dos lados, que es la misma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->foreignId('professional_profile_id')->nullable()->unique()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('professional_profile_id');
        });
    }
};
