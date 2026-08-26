<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compromisos internos, y evidencia gráfica de las tareas (§11).
 *
 * **Interno no es gratis.** Un proyecto para la propia Universidad se costea y
 * se valora igual que uno de fuera —ocupa máquina, material y gente—, pero no
 * entra dinero por él. Sin distinguirlo pasa una de dos cosas, y las dos son
 * malas: o se deja el valor en cero y el proyecto aparece siempre en pérdida,
 * o se le pone valor y el laboratorio parece haber facturado algo que nadie
 * pagó. Con la marca, el número sigue siendo el valor de lo que se obtuvo y
 * queda dicho que no es ingreso.
 *
 * Y la evidencia. «Se hizo» es una afirmación; una foto es una comprobación.
 * Dentro de dos años, cuando nadie recuerde el proyecto, la diferencia entre
 * las dos es todo lo que queda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('is_internal')->default(false)->after('source');
        });

        Schema::create('project_task_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('project_tasks')->cascadeOnDelete();

            // foto | video | enlace
            $table->string('kind')->default('foto');

            // Una foto subida, o un enlace a donde ya vive. Las dos formas son
            // reales: un video de dos minutos no tiene por que pasar por aqui,
            // y obligar a subirlo haria que nadie documente.
            $table->string('file_path')->nullable();
            $table->string('url')->nullable();

            $table->string('caption')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['task_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_task_evidence');

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('is_internal');
        });
    }
};
