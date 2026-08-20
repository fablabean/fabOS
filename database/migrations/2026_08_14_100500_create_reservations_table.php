<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El motor de reservas (§10). Un solo modelo polimorfico para espacios, activos
 * y personas, porque los tres se toman "durante un rango de tiempo".
 *
 * La pieza clave es la restriccion EXCLUDE: la imposibilidad de doble reserva
 * vive en PostgreSQL, no en una validacion de formulario. Ni una carrera de
 * concurrencia, ni un despliegue con bug, ni una carga masiva pueden crear dos
 * reservas superpuestas del mismo recurso.
 */
return new class extends Migration
{
    /** Estados en los que la reserva ocupa el recurso de verdad. */
    private const BLOQUEANTES = ['confirmada', 'en_curso'];

    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();

            $table->string('reservable_type');
            $table->unsignedBigInteger('reservable_id');

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Colaborador que acompaña, cuando la politica lo exige (§10)
            $table->foreignId('supervisor_id')->nullable()->constrained('users')->nullOnDelete();

            // solicitada | confirmada | en_curso | completada
            // rechazada  | cancelada  | no_show
            $table->string('status')->default('solicitada');

            // Como se origino: directa | con_aprobacion | solo_solicitud (§10)
            $table->string('mode')->default('directa');

            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');

            $table->timestampTz('checked_in_at')->nullable();
            $table->timestampTz('checked_out_at')->nullable();

            // Costo comprometido al reservar y liquidado al cerrar, en unidades
            // menores de FabCoin. El estimado se compromete; el real se cobra.
            $table->bigInteger('estimated_cost_minor')->default(0);
            $table->bigInteger('actual_cost_minor')->nullable();

            $table->text('purpose')->nullable();
            $table->text('status_reason')->nullable();

            $table->timestamps();

            $table->index(['reservable_type', 'reservable_id', 'starts_at']);
            $table->index(['user_id', 'starts_at']);
            $table->index('status');
        });

        // Rango temporal generado a partir de starts_at/ends_at: una sola fuente
        // de verdad, imposible que el rango y las columnas se desincronicen.
        DB::statement("
            ALTER TABLE reservations
            ADD COLUMN period tstzrange
            GENERATED ALWAYS AS (tstzrange(starts_at, ends_at, '[)')) STORED
        ");

        DB::statement('ALTER TABLE reservations ADD CONSTRAINT reservations_periodo_valido CHECK (ends_at > starts_at)');

        $bloqueantes = "'" . implode("','", self::BLOQUEANTES) . "'";

        DB::statement("
            ALTER TABLE reservations
            ADD CONSTRAINT reservations_sin_traslape
            EXCLUDE USING gist (
                reservable_type WITH =,
                reservable_id   WITH =,
                period          WITH &&
            )
            WHERE (status IN ({$bloqueantes}))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
