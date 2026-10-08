<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que se dice en un proyecto se puede corregir, y se sabe si quien lo pidió
 * lo abrió.
 *
 * `edited_at`: una errata en un mensaje ya enviado no tenía más arreglo que
 * borrarlo. `seen_at`: la primera vez que quien pidió el proyecto abrió su
 * página después de ese mensaje; hasta ahora se respondía y no se sabía si
 * había llegado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_comments', function (Blueprint $table) {
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('project_comments', function (Blueprint $table) {
            $table->dropColumn(['edited_at', 'seen_at']);
        });
    }
};
