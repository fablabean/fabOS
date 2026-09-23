<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La semana para volver a citar se cuenta desde la práctica (§9).
 *
 * Las matrículas marcadas «no aprobado» antes de que existiera `failed_at` —o
 * arregladas a mano— se quedaron sin fecha, y la cuenta caía en `updated_at`.
 * Eso no es cuándo reprobaron: es la última vez que alguien tocó la ficha, por
 * el motivo que fuera. Así que la semana se reiniciaba sola.
 *
 * Pasó de verdad: la práctica fue el 15, alguien editó la ficha el 22, y la
 * pantalla ofrecía citar de nuevo desde el 29 —dos semanas después de la
 * práctica, y subiendo con cada edición—.
 *
 * Se les pone la fecha de la práctica que no pasaron, que es el día en que
 * ocurrió. Si no hay ninguna registrada, queda el `updated_at` de siempre:
 * peor dato, pero no inventado.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE enrollments AS e
            SET failed_at = COALESCE(
                (
                    SELECT MAX(r.starts_at)
                    FROM reservations AS r
                    WHERE r.enrollment_id = e.id
                      AND r.mode = 'practica'
                      AND r.starts_at < NOW()
                ),
                e.updated_at
            )
            WHERE e.status = 'reprobado'
              AND e.failed_at IS NULL
        SQL);

        // Y al revés: una matrícula que ya no está reprobada no arrastra la
        // fecha de cuando lo estuvo. Si vuelve a reprobar, la semana tiene que
        // contarse desde esa vez.
        DB::table('enrollments')
            ->where('status', '<>', 'reprobado')
            ->whereNotNull('failed_at')
            ->update(['failed_at' => null]);
    }

    public function down(): void
    {
        // No se deshace: la fecha que había era la ausencia de fecha, y
        // devolverla sería volver a contar la semana desde la última edición.
    }
};
