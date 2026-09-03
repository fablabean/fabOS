<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que trae la lista y no cabe en las columnas fijas (§11).
 *
 * Cada convocatoria manda su tablero con sus propias columnas: puntaje, ruta,
 * modalidad, financiación, valor a financiar. Obligar a que todo eso quepa en
 * «nombre, organización, contacto, correo, descripción» era tirar la mitad de
 * lo que la convocatoria ya decidió. Ahora quien importa dice qué columna va a
 * dónde, y lo que no tiene sitio se guarda tal cual, con el nombre de su
 * columna, para leerlo al evaluar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->json('extra')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropColumn('extra');
        });
    }
};
