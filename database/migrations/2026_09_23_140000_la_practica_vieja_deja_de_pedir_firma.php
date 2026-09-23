<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Una práctica superada por otra deja de pedir firma (§9).
 *
 * Reprobar por el camino normal cierra la práctica que se evaluó, pero el
 * estado también se cambia desde el formulario del panel y a mano. Las que
 * pasaron por ahí se quedaron abiertas, y la pantalla seguía diciendo «falta
 * la firma» de la vez pasada —en rojo, con el aviso de que lleva más de un día
 * hábil— tapando a la cita nueva, que es lo que hay que mirar.
 *
 * Se cierran solo las que tienen otra práctica posterior en la misma
 * matrícula: que exista una cita más nueva es la prueba de que aquella
 * terminó. Una práctica pasada sin firmar y sin sucesora NO se toca: esa sí
 * está esperando a alguien, y cerrarla en silencio sería tapar el problema en
 * vez de resolverlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE reservations AS vieja
            SET status = 'completada',
                status_reason = 'Se citó de nuevo'
            WHERE vieja.mode = 'practica'
              AND vieja.status IN ('confirmada', 'en_curso')
              AND vieja.ends_at < NOW()
              AND vieja.enrollment_id IS NOT NULL
              AND EXISTS (
                  SELECT 1
                  FROM reservations AS nueva
                  WHERE nueva.enrollment_id = vieja.enrollment_id
                    AND nueva.mode = 'practica'
                    AND nueva.id <> vieja.id
                    AND nueva.starts_at > vieja.starts_at
                    AND nueva.status NOT IN ('cancelada', 'rechazada')
              )
        SQL);
    }

    public function down(): void
    {
        // No se deshace: reabrirlas volvería a pedir una firma que ya no
        // corresponde a nadie.
    }
};
