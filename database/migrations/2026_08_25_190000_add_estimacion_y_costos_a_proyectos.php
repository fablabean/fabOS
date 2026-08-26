<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que se cotizó, lo que se firmó, y lo que costó por fuera (§11).
 *
 * Dos cifras, no una. **Estimado** es lo que se puso en la propuesta;
 * **acordado** es lo que quedó en el contrato. Guardarlas en el mismo campo
 * borra la pregunta que más enseña de un laboratorio que cotiza: ¿cuánto se
 * mueve entre lo que ofrecemos y lo que nos aceptan? Con un solo número, la
 * respuesta se pierde en el momento de firmar.
 *
 * Y los costos asociados. El costeo ya reunía cuatro fuentes propias —máquina,
 * material, compras internas y horas del equipo—, pero un proyecto real gasta
 * en cosas que no pasan por ninguna: la factura de un tercero que hizo la
 * pintura, un flete, el alquiler de un equipo que no tenemos. Sin un sitio
 * donde anotarlas, el margen sale bonito y falso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->bigInteger('estimated_value')->default(0)->after('agreed_value');
        });

        Schema::create('project_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // servicio | material | flete | alquiler | otro
            $table->string('kind')->default('otro');
            $table->string('concept');
            $table->string('supplier')->nullable();

            $table->bigInteger('amount')->default(0);       // en pesos
            $table->date('incurred_on')->nullable();

            // Un costo sin respaldo es una cifra dicha de palabra. No se exige
            // -a veces el soporte llega despues- pero se pregunta.
            $table->string('document_ref')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['project_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_costs');

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('estimated_value');
        });
    }
};
