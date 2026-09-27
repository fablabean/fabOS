<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Lo que la regla vieja dio por ausencia en espacios y herramientas (§10).
 *
 * El barrido marcaba «no se presentó» a los veinte minutos cualquier espacio
 * con herramientas y cualquier herramienta: no tienen cómo validar la llegada
 * —una sala no tiene QR; la herramienta se toma de la estantería— y la gente
 * sí estaba. En septiembre de 2026 eran 66 de espacios y 107 de herramientas.
 * La regla nueva las da por hechas; esto corrige las que ya estaban marcadas,
 * para que el historial y los informes de uso no las cuenten como ausencias.
 *
 * No se cobra nada: al marcarlas se devolvió lo retenido, y préstamo de
 * espacios y herramientas no se cobra por minutos. Las máquinas, las
 * asesorías y las prácticas no se tocan: ahí la ausencia sí significa algo.
 */
return new class extends Migration
{
    private const NOTA = 'Sin registro de llegada ni de salida: se da por hecha. (Antes figuraba como «no se presentó».)';

    public function up(): void
    {
        $espacio = 'App\\Models\\Space';
        $equipo = 'App\\Models\\Asset';

        $herramientas = DB::table('assets')->where('kind', 'herramienta')->pluck('id');

        $salas = DB::table('reservations')->where('reservable_type', $espacio)->pluck('id');

        DB::table('reservations')
            ->where('status', 'no_show')
            ->where(fn ($q) => $q
                ->where('reservable_type', $espacio)
                ->orWhere(fn ($h) => $h->where('reservable_type', $equipo)->whereIn('reservable_id', $herramientas))
                ->orWhereIn('parent_reservation_id', $salas))
            ->whereNotIn('mode', ['asesoria', 'practica'])
            ->update([
                'status'        => 'completada',
                'status_reason' => self::NOTA,
                'updated_at'    => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('reservations')
            ->where('status_reason', self::NOTA)
            ->update(['status' => 'no_show', 'status_reason' => 'Nadie llegó dentro de la tolerancia']);
    }
};
