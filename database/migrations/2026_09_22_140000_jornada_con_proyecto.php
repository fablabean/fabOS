<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una jornada programada puede ser por un proyecto (§5, §11).
 *
 * «Apertura por la solicitud #280 · Lab. VR» era lo que se escribía en el
 * motivo, y no servía más que para leerse. Ahora la jornada puede colgar del
 * proyecto: se ve desde el proyecto cuánto tiempo extra costó, y desde la
 * jornada por qué se abrió.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('reason')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
        });
    }
};
