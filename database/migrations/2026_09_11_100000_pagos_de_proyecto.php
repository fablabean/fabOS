<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los pagos de un proyecto (§11).
 *
 * Cuando un proyecto exige un pago, el laboratorio lo pide: el cliente
 * recibe el valor y el QR del banco, paga, y responde con el comprobante,
 * su nombre completo y su documento. El laboratorio valida el comprobante,
 * y con eso el proyecto puede empezar a producirse. Cada pago es una fila:
 * un proyecto puede tener un anticipo y un saldo final.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->unsignedBigInteger('amount');              // pesos
            $table->string('concept')->nullable();             // «Anticipo del 50 %», «Saldo final»
            $table->string('status', 16)->default('solicitado');  // solicitado · enviado · validado · rechazado

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();

            // Lo que responde el cliente al pagar.
            $table->string('receipt_path')->nullable();
            $table->string('payer_name')->nullable();
            $table->string('payer_document', 64)->nullable();
            $table->timestamp('submitted_at')->nullable();

            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->text('notes')->nullable();                 // el motivo de un rechazo, una aclaracion

            $table->timestamps();

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_payments');
    }
};
