<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los entregables del proyecto, uno por línea (§11).
 *
 * Antes «qué se compromete a entregar» era un párrafo. Un párrafo no se puede
 * marcar como cumplido, no se puede repartir entre personas y no se puede
 * cruzar con el tablero: al final del proyecto nadie sabe si se entregó lo que
 * se prometió, sabe que se hizo mucho trabajo. Como lista, cada compromiso
 * tiene su propio estado y puede convertirse en una tarea —casi siempre un
 * hito, porque un entregable es exactamente eso: un compromiso con fecha—.
 *
 * El texto que ya estaba escrito se reparte en líneas y se conserva. Si esta
 * migración se revierte, se vuelve a juntar en el párrafo: nadie pierde lo que
 * escribió por deshacer un cambio de estructura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_deliverables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->text('detail')->nullable();
            $table->date('due_on')->nullable();
            $table->unsignedSmallInteger('position')->default(0);

            // La tarea en la que se convirtió, si ya se llevó al tablero. Que
            // sea nullable es el punto: un entregable existe desde que se
            // promete, mucho antes de que alguien planifique cómo hacerlo.
            $table->foreignId('task_id')->nullable()
                ->constrained('project_tasks')->nullOnDelete();

            // Se puede dar por entregado sin pasar por el tablero: no todo
            // compromiso necesita una tarea para cumplirse.
            $table->timestampTz('delivered_at')->nullable();

            $table->timestamps();

            $table->index(['project_id', 'position']);
        });

        // Lo ya escrito, repartido en líneas.
        foreach (DB::table('projects')->whereNotNull('objective')->get() as $proyecto) {
            $lineas = preg_split('/\r\n|\r|\n/', (string) $proyecto->objective);
            $posicion = 0;

            foreach ($lineas as $linea) {
                // Se limpian viñetas escritas a mano: quien las puso quería una
                // lista, y ahora la lista la hace la estructura.
                $titulo = trim(preg_replace('/^\s*[-*•·]\s*/u', '', $linea));

                if ($titulo === '') {
                    continue;
                }

                DB::table('project_deliverables')->insert([
                    'project_id' => $proyecto->id,
                    'title'      => mb_substr($titulo, 0, 255),
                    'position'   => $posicion++,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('objective');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->text('objective')->nullable()->after('summary');
        });

        // Se vuelve a juntar en el párrafo, para no perder nada al deshacer.
        $porProyecto = DB::table('project_deliverables')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->groupBy('project_id');

        foreach ($porProyecto as $proyectoId => $entregables) {
            DB::table('projects')->where('id', $proyectoId)->update([
                'objective' => $entregables->pluck('title')->implode("\n"),
            ]);
        }

        Schema::dropIfExists('project_deliverables');
    }
};
