<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La tienda (§14).
 *
 * Se venden dos cosas distintas y conviene no confundirlas:
 *
 *  - **Insumos**, que salen del inventario. Vender medio kilo de filamento
 *    tiene que descontarlo de la existencia, o la tienda y el inventario
 *    empiezan a contar historias diferentes el mismo día que se abre.
 *  - **Servicios especiales**, que no tocan el inventario: un trabajo por
 *    encargo, una asesoría, una impresión hecha por el equipo.
 *
 * Se paga en FabCoins. La venta se registra completa aunque el cobro esté
 * apagado —la existencia sí se mueve—, para poder ensayar el mostrador antes
 * de encender el dinero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();                  // VTA-2026-0001

            // A quién se le vende y quién atiende. Son personas distintas.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('served_by')->nullable()->constrained('users')->nullOnDelete();

            // abierta | pagada | anulada
            $table->string('status')->default('abierta');

            // Total cobrado, en unidades menores de FabCoin. Se congela al
            // cobrar: cambiar una tarifa despues no debe reescribir el pasado.
            $table->bigInteger('total_minor')->default(0);

            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();

            // Si sale del inventario, se enlaza. Si es un servicio, va suelto.
            $table->foreignId('supply_id')->nullable()->constrained()->nullOnDelete();

            $table->string('description');
            $table->string('unit')->default('unidad');
            $table->decimal('quantity', 12, 3);

            // Precio unitario congelado al momento de la venta.
            $table->bigInteger('unit_price_minor')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};
