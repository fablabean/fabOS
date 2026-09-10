<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La foto de la persona (§5).
 *
 * En la barra del sitio, la cuenta se compacta en un círculo con la foto o,
 * a falta de ella, con las iniciales. La foto la sube cada quien desde Mi
 * cuenta, o la coordinación desde la ficha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('photo_path')->nullable()->after('nick');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });
    }
};
