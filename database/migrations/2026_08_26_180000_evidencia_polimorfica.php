<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La evidencia deja de ser solo de las tareas (§11).
 *
 * Nació colgando de `project_tasks` porque ahí hizo falta primero. Pero lo que
 * hay que probar no siempre es una tarea: un **entregable** se demuestra con el
 * archivo definitivo que se entregó, y una **producción** con el .stl y el
 * .gcode que salieron de la máquina —los tenga un proyecto detrás o sea la
 * pieza de un estudiante—.
 *
 * Tres tablas casi idénticas se habrían separado a la primera diferencia. Una
 * sola, polimórfica, mantiene el mismo comportamiento en los tres sitios: el
 * mismo disco privado, la misma ruta con sesión, la misma forma de mirarla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evidencias', function (Blueprint $table) {
            $table->id();

            $table->string('evidenciable_type');
            $table->unsignedBigInteger('evidenciable_id');

            // foto | video | enlace | archivo
            $table->string('kind')->default('foto');

            $table->string('file_path')->nullable();
            $table->string('url')->nullable();
            $table->string('caption')->nullable();

            // Como se llamaba el archivo al subirlo. Un «carcasa-v3-final.stl»
            // dice mas que el nombre aleatorio con que se guarda.
            $table->string('original_name')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['evidenciable_type', 'evidenciable_id']);
        });

        // Lo que ya estaba colgado de una tarea se muda tal cual.
        if (Schema::hasTable('project_task_evidence')) {
            foreach (DB::table('project_task_evidence')->orderBy('id')->get() as $fila) {
                DB::table('evidencias')->insert([
                    'evidenciable_type' => \App\Models\ProjectTask::class,
                    'evidenciable_id'   => $fila->task_id,
                    'kind'              => $fila->kind,
                    'file_path'         => $fila->file_path,
                    'url'               => $fila->url,
                    'caption'           => $fila->caption,
                    'uploaded_by'       => $fila->uploaded_by,
                    'created_at'        => $fila->created_at,
                    'updated_at'        => $fila->updated_at,
                ]);
            }

            Schema::dropIfExists('project_task_evidence');
        }
    }

    public function down(): void
    {
        Schema::create('project_task_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('project_tasks')->cascadeOnDelete();
            $table->string('kind')->default('foto');
            $table->string('file_path')->nullable();
            $table->string('url')->nullable();
            $table->string('caption')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['task_id', 'kind']);
        });

        // Solo vuelve lo que era de una tarea: lo demás no cabe allí, y
        // fingir que sí lo haría desaparecer en silencio.
        $deTareas = DB::table('evidencias')
            ->where('evidenciable_type', \App\Models\ProjectTask::class)
            ->orderBy('id')
            ->get();

        foreach ($deTareas as $fila) {
            DB::table('project_task_evidence')->insert([
                'task_id'     => $fila->evidenciable_id,
                'kind'        => $fila->kind,
                'file_path'   => $fila->file_path,
                'url'         => $fila->url,
                'caption'     => $fila->caption,
                'uploaded_by' => $fila->uploaded_by,
                'created_at'  => $fila->created_at,
                'updated_at'  => $fila->updated_at,
            ]);
        }

        Schema::dropIfExists('evidencias');
    }
};
