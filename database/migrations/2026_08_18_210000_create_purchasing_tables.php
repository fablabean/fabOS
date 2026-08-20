<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Presupuesto, compras e insumos (§13).
 *
 * El módulo existe por una necesidad concreta: el laboratorio no compra, pide.
 * Lo que sale de aquí es una **requisición** que se le entrega al área de
 * compras de la Universidad, y lo que vuelve son cajas que hay que meter al
 * inventario. Entre esos dos extremos pasa lo que suele perderse: qué se pidió,
 * contra qué presupuesto, quién lo aprobó y qué llegó de verdad.
 *
 * Tres decisiones de fondo:
 *
 *  - **Pesos enteros, no FabCoins.** Esta es plata real de la Universidad. Los
 *    FabCoins son la economía interna y no se mezclan.
 *  - **El saldo del presupuesto se deriva**, igual que en el libro contable:
 *    comprometido por lo aprobado, ejecutado por lo recibido. Nunca un campo
 *    que alguien pueda ajustar a mano.
 *  - **Recibir es parcial por naturaleza.** Casi nunca llega todo junto, y un
 *    modelo que exija recibir de una vez obliga a mentir para poder cerrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedSmallInteger('year');
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();

            // Pesos enteros: el presupuesto se aprueba en cifras redondas.
            $table->bigInteger('amount')->default(0);

            // borrador | vigente | cerrado
            $table->string('status')->default('vigente');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['year', 'status']);
        });

        // Insumos: lo que se consume y se repone. Distinto de un activo, que es
        // una unidad identificable con placa y QR propios.
        Schema::create('supplies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('sku')->nullable()->unique();
            $table->string('unit')->default('unidad');       // g, ml, hoja, m, unidad
            $table->text('description')->nullable();

            // Existencia actual. Se mueve SOLO por movimientos registrados.
            $table->decimal('stock', 12, 3)->default(0);
            $table->decimal('reorder_point', 12, 3)->nullable();

            // Último costo conocido por unidad, en pesos. Sirve para estimar la
            // próxima compra sin tener que buscar la factura anterior.
            $table->bigInteger('last_cost')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('supply_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supply_id')->constrained()->cascadeOnDelete();

            // entrada | salida | ajuste
            $table->string('kind');
            $table->decimal('quantity', 12, 3);
            $table->decimal('balance_after', 12, 3);

            $table->nullableMorphs('reference');            // recepción, reserva, venta
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('memo')->nullable();
            $table->timestamps();

            $table->index(['supply_id', 'created_at']);
        });

        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();               // COM-2026-0001
            $table->foreignId('budget_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            // borrador | enviada | aprobada | rechazada | en_compra
            // recibida_parcial | recibida | cancelada
            $table->string('status')->default('borrador');
            $table->string('justification')->nullable();     // para qué se necesita
            $table->text('notes')->nullable();

            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestampTz('closed_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('purchase_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained()->cascadeOnDelete();

            // Si repone un insumo conocido, se enlaza y la recepción entra sola
            // al inventario. Si es algo nuevo, va suelto y se describe.
            $table->foreignId('supply_id')->nullable()->constrained()->nullOnDelete();

            $table->string('description');
            $table->string('unit')->default('unidad');
            $table->decimal('quantity', 12, 3);
            $table->bigInteger('unit_price')->default(0);    // pesos, estimado
            $table->decimal('received_quantity', 12, 3)->default(0);

            $table->string('supplier')->nullable();
            $table->string('reference_url')->nullable();     // enlace al producto
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_request_items');
        Schema::dropIfExists('purchase_requests');
        Schema::dropIfExists('supply_movements');
        Schema::dropIfExists('supplies');
        Schema::dropIfExists('budgets');
    }
};
