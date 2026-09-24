<?php

use App\Models\Asset;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La máquina que trabaja sola se puede dejar sola seis horas (§7, §8).
 *
 * Quien tiene el certifab de una impresora 3D puede reservarla en uso propio,
 * pero al elegir la duración le salía «requiere visto bueno» en todo lo que
 * pasara de una hora. Las veinte impresoras nacieron con los sesenta minutos
 * del valor por defecto de la tabla, que está pensado para lo que se usa de
 * pie —una sierra, una pulidora—, y a una impresión de tres horas le ponía
 * encima una aprobación que nadie quería dar.
 *
 * Se sube a seis horas donde el equipo está marcado como desatendido, que es
 * exactamente la condición de la que hablamos: el trabajo corre sin la persona
 * presente. Quedan en una hora la estación de lavado y curado y las dos
 * impresoras 2D, que no lo están, y los robots, que tienen su cero puesto a
 * mano.
 *
 * Solo toca los que siguen en el valor por defecto: si alguien ya ajustó la
 * autonomía de un equipo, esa decisión manda sobre esta.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('assets')
            ->where('unattended_use', true)
            ->where('autonomous_minutes', 60)
            ->update(['autonomous_minutes' => Asset::AUTONOMIA_DESATENDIDA]);
    }

    public function down(): void
    {
        DB::table('assets')
            ->where('unattended_use', true)
            ->where('autonomous_minutes', Asset::AUTONOMIA_DESATENDIDA)
            ->update(['autonomous_minutes' => 60]);
    }
};
