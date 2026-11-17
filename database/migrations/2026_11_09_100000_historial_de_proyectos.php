<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El historial de un proyecto: quién lo tuvo a cargo, por qué etapas y
 * estados pasó, quién hizo cada cambio y cuándo.
 *
 * Al cambiar el responsable se perdía el anterior: el proyecto 272 pasó de
 * una persona a otra y no quedó dónde ver de quién era. Se guardan los
 * nombres como estaban en ese momento, no solo los ids: si alguien se va del
 * laboratorio, su paso por el proyecto sigue leyéndose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('field', 20);          // responsable · etapa · estado
            $table->string('from_value')->nullable();
            $table->string('to_value')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_changes');
    }
};
