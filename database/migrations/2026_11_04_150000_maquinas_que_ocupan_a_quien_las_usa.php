<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las máquinas que no trabajan solas: la termofijadora la opera alguien de
 * principio a fin. Si quien la reservó es del equipo, esa franja lo ocupa y
 * no se le asignan asesorías ni revisiones encima. Apagado, como hasta hoy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->boolean('ocupa_a_quien_la_usa')->default(false)->after('unattended_use');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn('ocupa_a_quien_la_usa');
        });
    }
};
