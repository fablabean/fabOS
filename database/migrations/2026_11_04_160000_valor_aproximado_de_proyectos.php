<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El valor que el laboratorio le pone a un proyecto que no tenía ninguno,
 * según su área, para contarlo como trabajo interno.
 *
 * Guarda cómo estaba antes —«interno» si ya lo era, «cliente» si se pasó a
 * interno al aproximarlo— para poder deshacerlo sin adivinar. Nulo es que el
 * valor lo puso una persona, como siempre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('aproximado_de', 16)->nullable()->after('estimated_value');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('aproximado_de');
        });
    }
};
