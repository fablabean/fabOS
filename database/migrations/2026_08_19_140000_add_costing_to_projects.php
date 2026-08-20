<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Costeo real de un proyecto (§11, §12).
 *
 * Un proyecto cuesta cuatro cosas, y hasta ahora cada una vivía en su tabla sin
 * hablar con las demás: **tiempo de máquina** (reservas), **material**
 * (inventario), **compras** hechas para él y **tiempo de la gente**. Enlazarlas
 * es lo que permite responder la pregunta que la Universidad siempre hace y que
 * casi ningún laboratorio sabe contestar: *¿este proyecto dejó o costó?*
 *
 * Todo se valora en **pesos**, no en FabCoins. Los FabCoins asignan capacidad
 * interna; el informe de un proyecto se lee fuera del laboratorio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('user_id')
                ->constrained()->nullOnDelete();
        });

        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('budget_id')
                ->constrained()->nullOnDelete();
        });

        Schema::create('project_time_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Externos que facturan aparte: un proveedor no tiene cuenta pero su
            // tiempo sí cuesta.
            $table->string('external_name')->nullable();

            $table->date('worked_on');
            $table->decimal('hours', 6, 2);
            $table->string('activity')->nullable();

            // Costo por hora congelado al registrar. No se guarda el sueldo de
            // nadie: es una tarifa de referencia del laboratorio, y guardarla en
            // la línea evita que un cambio futuro reescriba proyectos cerrados.
            $table->bigInteger('hourly_cost')->default(0);

            $table->timestamps();

            $table->index(['project_id', 'worked_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_time_logs');

        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
        });
    }
};
