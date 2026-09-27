<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Las preguntas frecuentes, cada una en su área (§10).
 *
 * El área es como se filtra y como se clasifica lo que pregunta la gente, y
 * las frecuentes no tenían ninguna: no salían en ningún filtro. Las de cómo
 * funciona el laboratorio van a General; la de horas incluidas, a Impresión
 * 3D, que es donde hoy aplican. Si el área no existe en esta instalación, se
 * quedan como estaban.
 */
return new class extends Migration
{
    public function up(): void
    {
        $general = DB::table('areas')->where('slug', 'general')->value('id');
        $impresion3d = DB::table('areas')->where('slug', 'impresion-3d')->value('id');

        if ($impresion3d) {
            DB::table('questions')
                ->where('slug', 'que-son-las-horas-incluidas')
                ->whereNull('area_id')
                ->update(['area_id' => $impresion3d]);
        }

        if ($general) {
            DB::table('questions')
                ->where('frecuente', true)
                ->whereNull('area_id')
                ->update(['area_id' => $general]);
        }
    }

    public function down(): void
    {
        // Las áreas asignadas se quedan: quitarlas no devuelve nada útil.
    }
};
