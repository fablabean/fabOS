<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los botones del banner son solo los que se escribieron (§3).
 *
 * Una lámina sin botones salía con dos «de siempre» —ver los equipos y
 * proponer un proyecto—. Pero a veces no se quiere ninguno: una lámina que
 * solo anuncia, o que lleva a su QR. No había forma de decirlo.
 *
 * Para que la portada no cambie sola con este despliegue, las láminas que
 * hoy salen con los de siempre los reciben escritos. Quien no los quiera los
 * borra de la lámina, que es justo lo que antes no se podía hacer.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('banners')
            ->where(fn ($q) => $q->whereNull('accion_texto')->orWhere('accion_texto', ''))
            ->where(fn ($q) => $q->whereNull('accion2_texto')->orWhere('accion2_texto', ''))
            ->update([
                'accion_texto'  => 'Ver los equipos',
                'accion_url'    => '/reservas',
                'accion2_texto' => 'Proponer un proyecto',
                'accion2_url'   => '/proyectos/solicitar',
            ]);
    }

    public function down(): void
    {
        // Lo escrito se queda: quitarlo dejaría láminas sin salida.
    }
};
