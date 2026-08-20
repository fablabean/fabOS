<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cola de producción: los encargos de la tienda (§14).
 *
 * No todo el mundo quiere —ni puede— operar una máquina. Un profesor que
 * necesita cuarenta piezas para una clase no va a certificarse en corte láser:
 * quiere entregar un archivo y recoger las piezas. Eso es un **encargo**, y sin
 * un sitio donde vivan se acumulan en el WhatsApp de quien coordina hasta que
 * alguno se pierde.
 *
 *   solicitado → cotizado → aceptado → en cola → en producción → listo → entregado
 *
 * Dos decisiones:
 *
 *  - **Se cotiza antes de producir.** Quien pide acepta un precio y un plazo; el
 *    laboratorio no empieza a gastar material sobre un «sí, hágamelo».
 *  - **Entregar genera una venta**, con su línea de servicio y sus líneas de
 *    material. Así el cobro, el descuento de inventario y el precio congelado
 *    salen del mismo camino que el mostrador, en vez de tener dos formas
 *    distintas de cobrar que tarde o temprano se contradicen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();                  // ENC-2026-0001

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();      // quien pide
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            // Con qué se va a hacer. Puede no saberse al pedirlo.
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();

            // Si el encargo es para un proyecto, su costo entra en el costeo.
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('file_url')->nullable();             // el archivo a fabricar
            $table->decimal('quantity', 12, 3)->default(1);

            // solicitado | cotizado | aceptado | en_cola | en_produccion
            // listo | entregado | rechazado | cancelado
            $table->string('status')->default('solicitado');
            $table->string('priority')->default('normal');      // baja | normal | alta

            // La cotización, congelada al aceptarla.
            $table->unsignedInteger('quoted_minutes')->nullable();
            $table->bigInteger('quoted_total_minor')->default(0);
            $table->text('quote_notes')->nullable();
            $table->timestampTz('quoted_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();

            $table->date('due_on')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();

            // La venta que lo cobró. Es la que mueve saldo e inventario.
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();

            $table->text('notes')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamps();

            $table->index(['status', 'priority', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_jobs');
    }
};
