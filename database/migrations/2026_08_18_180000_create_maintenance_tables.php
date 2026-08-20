<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mantenimiento preventivo y correctivo (§8).
 *
 * El formulario de control que hoy se diligencia en papel se guarda como
 * plantilla en JSONB y se VERSIONA con cada orden: una orden de hace dos años
 * conserva el formulario con el que realmente se llenó, no el actual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // Sobre un equipo puntual o sobre toda una familia de riesgo:
            // las cuatro impresoras de resina comparten rutina.
            $table->foreignId('asset_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('risk_family_id')->nullable()->constrained()->cascadeOnDelete();

            // Periodicidad por calendario o por uso. Una láser no se mide en
            // días sino en horas de corte.
            $table->unsignedSmallInteger('every_days')->nullable();
            $table->unsignedInteger('every_usage_minutes')->nullable();

            // Plantilla del formulario: campos de sí/no, medición y foto.
            $table->json('checklist')->nullable();

            $table->boolean('is_active')->default(true);
            $table->text('instructions')->nullable();
            $table->timestamps();
        });

        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('maintenance_plan_id')->nullable()->constrained()->nullOnDelete();

            $table->string('kind');       // preventivo | correctivo
            $table->string('status')->default('abierta');   // abierta|en_proceso|cerrada|cancelada
            $table->string('priority')->default('normal');  // baja|normal|alta|critica

            $table->text('reported_issue')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            // El formulario tal como se diligenció, congelado con su plantilla.
            $table->json('checklist_snapshot')->nullable();
            $table->json('checklist_answers')->nullable();

            $table->text('diagnosis')->nullable();
            $table->text('work_done')->nullable();
            $table->decimal('cost', 14, 2)->nullable();

            // Paro: lo que se usa para calcular disponibilidad y MTTR.
            $table->boolean('stops_equipment')->default(false);
            $table->timestampTz('down_since')->nullable();
            $table->timestampTz('up_since')->nullable();

            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestamps();

            $table->index(['asset_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_orders');
        Schema::dropIfExists('maintenance_plans');
    }
};
