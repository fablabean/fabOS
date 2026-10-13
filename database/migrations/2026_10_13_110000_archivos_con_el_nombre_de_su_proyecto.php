<?php

use App\Models\Evidencia;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cada archivo sabe de qué proyecto es, y lo lleva en el nombre.
 *
 * `project_id`: una evidencia cuelga de un proyecto, de una tarea, de un
 * entregable o de una propuesta, y para saber de qué proyecto era había que
 * subir por ahí. Con la columna se pueden listar todos los archivos de todos
 * los proyectos en una sola pantalla, y numerarlos por proyecto.
 *
 * Y se renombran los que ya hay, con la misma regla que los nuevos
 * (Evidencia::nombreOrdenado): código, fecha, consecutivo del día y el nombre
 * que tenían. No se toca el archivo en el disco, solo cómo se llama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evidencias', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
        });

        // Solo si el proyecto sigue existiendo: hay evidencias de proyectos
        // que se borraron, y enlazarlas rompería la llave. Esas se quedan sin
        // proyecto, que es la verdad.
        DB::statement(
            'update evidencias set project_id = evidenciable_id
              where evidenciable_type = ? and exists (select 1 from projects p where p.id = evidencias.evidenciable_id)',
            [\App\Models\Project::class],
        );

        foreach ([
            \App\Models\ProjectDeliverable::class => 'project_deliverables',
            \App\Models\ProjectTask::class        => 'project_tasks',
            \App\Models\ProjectProposal::class    => 'project_proposals',
            \App\Models\Reservation::class        => 'reservations',
        ] as $clase => $tabla) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }

            DB::statement(
                "update evidencias set project_id = (
                    select t.project_id from {$tabla} t
                     where t.id = evidencias.evidenciable_id
                       and exists (select 1 from projects p where p.id = t.project_id))
                 where evidenciable_type = ?",
                [$clase],
            );
        }

        $tz = config('fabos.lab.timezone');
        $codigos = DB::table('projects')->pluck('code', 'id');
        $consecutivos = [];

        DB::table('evidencias')
            ->whereNotNull('project_id')
            ->whereNotNull('file_path')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'project_id', 'original_name', 'file_path', 'kind', 'created_at'])
            ->each(function ($e) use ($tz, $codigos, &$consecutivos) {
                if (! isset($codigos[$e->project_id])) {
                    return;
                }

                $fecha = Carbon::parse($e->created_at, 'UTC')->timezone($tz);
                $clave = $e->project_id . '|' . $fecha->format('Ymd');
                $n = $consecutivos[$clave] = ($consecutivos[$clave] ?? 0) + 1;

                DB::table('evidencias')->where('id', $e->id)->update([
                    'original_name' => Evidencia::nombreOrdenado(
                        $codigos[$e->project_id], $fecha, $n, $e->original_name, $e->file_path, $e->kind,
                    ),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('evidencias', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
        });
    }
};
