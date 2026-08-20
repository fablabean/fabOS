<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * btree_gist permite combinar comparaciones de igualdad (=) con operadores de
 * rango (&&) dentro de una misma restriccion EXCLUDE. Sin esta extension no se
 * puede garantizar en la base de datos que dos reservas del mismo recurso no
 * se traslapen: quedaria dependiendo de validaciones en PHP.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
    }

    public function down(): void
    {
        DB::statement('DROP EXTENSION IF EXISTS btree_gist');
    }
};
