<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proyectos (§11).
 *
 * El embudo va de una idea suelta que llegó por correo o por WhatsApp hasta un
 * acta de cierre, y cada paso tiene una **compuerta documental**: no se avanza
 * de etapa sin el documento que la sostiene. No es burocracia —es lo que evita
 * el patrón que mata proyectos en los laboratorios: empezar a fabricar sobre un
 * acuerdo verbal y descubrir a mitad de camino que cada quien entendió una cosa
 * distinta.
 *
 *   idea → propuesta → contrato → brief → ejecución → cierre
 *
 * Dos decisiones más:
 *
 *  - **Quien pide puede no tener cuenta.** Una idea que llega por WhatsApp de
 *    una empresa no debería exigir registro para quedar anotada. Por eso el
 *    contacto se guarda como texto y la cuenta es opcional.
 *  - **Gantt y Kanban salen de la MISMA tabla de tareas.** Son dos formas de
 *    mirar lo mismo: si fueran tablas distintas, tarde o temprano contarían
 *    cosas diferentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();                 // PRY-2026-0001
            $table->string('name');

            // idea | propuesta | contrato | brief | ejecucion | cierre
            $table->string('stage')->default('idea');
            // activo | ganado | perdido | descartado | cerrado
            $table->string('status')->default('activo');

            // De dónde llegó: correo, WhatsApp, formulario del sitio, interno.
            $table->string('source')->default('correo');

            // Quien pide. Puede no tener cuenta: una empresa que escribe por
            // WhatsApp no debería registrarse para que le anoten la idea.
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('organization')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            // El laboratorio responde como institución, pero siempre recae en
            // una persona: sin responsable, un proyecto no avanza.
            $table->foreignId('lead_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();

            $table->text('summary')->nullable();          // la idea en dos frases
            $table->text('objective')->nullable();        // qué se compromete a entregar
            $table->text('notes')->nullable();

            // Valor acordado, en pesos. Es plata real, como las compras.
            $table->bigInteger('agreed_value')->default(0);

            $table->date('starts_on')->nullable();
            $table->date('due_on')->nullable();

            $table->timestampTz('closed_at')->nullable();
            $table->text('closing_notes')->nullable();

            $table->timestamps();

            $table->index(['stage', 'status']);
        });

        // El equipo. Puede incluir proveedores y al propio cliente: en un
        // proyecto real todos ellos tienen que aparecer en el acta.
        Schema::create('project_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Externos sin cuenta: proveedor, cliente, invitado.
            $table->string('external_name')->nullable();
            $table->string('organization')->nullable();

            // responsable | equipo | proveedor | cliente
            $table->string('role')->default('equipo');
            $table->string('note')->nullable();

            $table->timestamps();
        });

        // Las compuertas. Cada etapa exige el documento que la sostiene.
        Schema::create('project_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // propuesta | contrato | brief | acta | informe | otro
            $table->string('kind');
            $table->string('title');

            // Un documento vive como archivo subido o como enlace a Drive: las
            // dos formas son reales y obligar a una sola haría que la gente
            // documente por fuera del sistema.
            $table->string('file_path')->nullable();
            $table->string('url')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('signed_on')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['project_id', 'kind']);
        });

        // Una sola tabla para Gantt y Kanban.
        Schema::create('project_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            // por_hacer | en_curso | bloqueada | hecha  → columnas del Kanban
            $table->string('status')->default('por_hacer');

            // Fechas → barras del Gantt. Sin ellas la tarea solo vive en el tablero.
            $table->date('starts_on')->nullable();
            $table->date('due_on')->nullable();

            // Un hito es una tarea sin duración que marca un compromiso: de esos
            // se levanta acta.
            $table->boolean('is_milestone')->default(false);

            $table->unsignedSmallInteger('progress')->default(0);   // 0 a 100
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestampTz('completed_at')->nullable();

            $table->timestamps();

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_tasks');
        Schema::dropIfExists('project_documents');
        Schema::dropIfExists('project_members');
        Schema::dropIfExists('projects');
    }
};
