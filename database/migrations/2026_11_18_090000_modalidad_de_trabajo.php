<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modalidad de trabajo del equipo (§5).
 *
 * El horario semanal (`work_schedules`) ya decía, franja por franja, si era
 * presencial o remota. Faltaban tres cosas:
 *
 * - **La modalidad de cada persona** —normal, híbrida o flexible—, que dice
 *   qué puede cambiar ella misma. La flexible es la de siempre en la nómina
 *   (`contrato_flexible`): se copia para no perderla.
 * - **El horario de un día concreto** (`jornadas_del_dia`): «el martes 25 voy
 *   remoto», «el jueves trabajo de 14:00 a 22:00». Si una persona tiene
 *   bloques en una fecha, esos reemplazan su horario semanal ese día; los
 *   demás días sigue el de siempre.
 * - **El enlace de reunión** de cada persona, y en la asesoría si fue
 *   presencial o virtual: quien la pide tiene que saberlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('modalidad_trabajo', 12)->default('normal')->after('contrato_flexible');
            $table->string('enlace_reunion')->nullable()->after('modalidad_trabajo');
        });

        DB::table('users')->where('contrato_flexible', true)->update(['modalidad_trabajo' => 'flexible']);

        Schema::create('jornadas_del_dia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('fecha');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('modalidad', 20)->default('presencial');
            $table->string('nota')->nullable();
            // La coordinación pasó por encima de la regla del mínimo por área.
            $table->boolean('forzado')->default(false);
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'fecha']);
            $table->index('fecha');
        });

        Schema::table('reservations', function (Blueprint $table) {
            // Solo las asesorías: presencial o virtual.
            $table->string('modalidad_asesoria', 12)->nullable()->after('mode');
        });

        // Los avisos de asesoría dicen dónde es. A las plantillas que ya existen
        // se les añade la línea, sin tocar lo que alguien haya editado.
        foreach (['asesoria.confirmada' => 'Te atiende: {asesor}', 'asesoria.asignada' => 'Lo que quiere hacer: {motivo}'] as $clave => $despues) {
            $plantilla = DB::table('notification_templates')->where('key', $clave)->first();

            if ($plantilla && ! str_contains((string) $plantilla->body, '{donde}') && str_contains((string) $plantilla->body, $despues)) {
                DB::table('notification_templates')->where('key', $clave)->update([
                    'body' => str_replace($despues, $despues . "\nDónde: {donde}", $plantilla->body),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('reservations', fn (Blueprint $table) => $table->dropColumn('modalidad_asesoria'));
        Schema::dropIfExists('jornadas_del_dia');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['modalidad_trabajo', 'enlace_reunion']));
    }
};
