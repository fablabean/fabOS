<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cada propuesta que se manda queda guardada (§11).
 *
 * Una propuesta se negocia: se manda, el cliente pide bajar el alcance, se
 * manda otra. Si cada envío pisa al anterior, a la tercera nadie sabe qué se
 * ofreció la primera vez —y esa es justo la pregunta que aparece cuando alguien
 * dice «pero ustedes habían dicho…»—.
 *
 * Se guarda el contenido, no una referencia: los entregables de la v1 pueden
 * haberse borrado al redactar la v2, y una versión que apunta a algo que ya no
 * existe no es una versión.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('version');

            $table->bigInteger('estimated_value')->default(0);
            $table->date('starts_on')->nullable();
            $table->date('due_on')->nullable();
            $table->text('message')->nullable();

            // El contenido congelado, no una referencia: los entregables de la
            // v1 pueden no existir cuando se redacte la v2.
            $table->json('deliverables')->nullable();

            $table->timestampTz('sent_at');
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['project_id', 'version']);
        });

        // Lo ya mandado se conserva como v1: perder el histórico al estrenar
        // el histórico seria una broma pesada.
        foreach (DB::table('projects')->whereNotNull('proposal_sent_at')->get() as $p) {
            $entregables = DB::table('project_deliverables')
                ->where('project_id', $p->id)
                ->orderBy('position')
                ->get(['title', 'due_on'])
                ->map(fn ($e) => ['title' => $e->title, 'due_on' => $e->due_on])
                ->all();

            DB::table('project_proposals')->insert([
                'project_id'      => $p->id,
                'version'         => 1,
                'estimated_value' => $p->estimated_value ?? 0,
                'starts_on'       => $p->starts_on,
                'due_on'          => $p->due_on,
                'deliverables'    => json_encode($entregables),
                'sent_at'         => $p->proposal_sent_at,
                'created_at'      => $p->proposal_sent_at,
                'updated_at'      => $p->proposal_sent_at,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_proposals');
    }
};
