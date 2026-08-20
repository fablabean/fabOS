<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modos de reserva por recurso y lista de espera (§10).
 *
 * Hasta ahora todo equipo se reservaba igual: si la persona era autónoma, la
 * reserva quedaba confirmada. Pero no todos los equipos son iguales —el
 * humanoide se pide, no se reserva— y hacía falta poder decirlo por recurso:
 *
 *  - **directa**: quien está habilitado reserva y listo.
 *  - **con_aprobacion**: siempre pasa por el visto bueno de la coordinación,
 *    por muy autónoma que sea la persona.
 *  - **solo_solicitud**: no se reserva, se pide. Es lo correcto para lo que
 *    exige montar algo, abrir el laboratorio o acompañar sí o sí.
 *
 * Y la pieza que faltaba de verdad: **poder pedir fuera de la jornada**. Una
 * solicitud para un sábado no bloquea el equipo —no queda vigente— pero queda
 * anotada, que es justo lo que hoy se pierde en un chat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            // directa | con_aprobacion | solo_solicitud
            $table->string('booking_mode')->default('directa')->after('is_reservable');

            // Si admite pedidos fuera de la franja atendida. El humanoide sí;
            // una impresora de escritorio no tendría por qué.
            $table->boolean('allows_off_hours_requests')->default(false)->after('booking_mode');
        });

        Schema::create('waitlist_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // La ventana en la que a esa persona le sirve el equipo.
            $table->timestampTz('wants_from');
            $table->timestampTz('wants_until');

            // esperando | avisado | atendido | vencido | cancelado
            $table->string('status')->default('esperando');
            $table->timestampTz('notified_at')->nullable();
            $table->string('note')->nullable();

            $table->timestamps();

            $table->index(['asset_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_entries');

        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn(['booking_mode', 'allows_off_hours_requests']);
        });
    }
};
