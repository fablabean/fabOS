<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Certifabs: la habilitación real para operar un equipo (§5, §10).
 *
 * El nivel del curso es prerrequisito; lo que abre la reserva es esto. Se
 * otorga sobre una FAMILIA DE RIESGO —habilita todo lo de esa familia— o sobre
 * un ACTIVO puntual, para equipos que requieren inducción propia.
 *
 * Quién lo otorga queda registrado: es una habilitación trazable, no un favor
 * recordado. Y caduca, porque una inducción de hace tres años no vale igual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certifabs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Uno de los dos, nunca ambos ni ninguno (restricción más abajo).
            $table->foreignId('risk_family_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('level')->default('byte');   // bit|byte|kilo|mega|giga|tera

            // Minutos que puede reservar sin visto bueno del responsable.
            // Nulo = se usa el valor configurado en el activo.
            $table->unsignedSmallInteger('max_autonomous_minutes')->nullable();

            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('granted_via')->default('asesoria');   // asesoria|curso|migracion
            $table->timestampTz('granted_at')->useCurrent();
            $table->timestampTz('expires_at')->nullable();          // nulo = sin vencimiento
            $table->timestampTz('revoked_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });

        // Un certifab apunta a una familia o a un activo, nunca a los dos.
        DB::statement("
            ALTER TABLE certifabs
            ADD CONSTRAINT certifabs_alcance_unico
            CHECK (
                (risk_family_id IS NOT NULL AND asset_id IS NULL)
                OR (risk_family_id IS NULL AND asset_id IS NOT NULL)
            )
        ");

        // Evita duplicados vivos del mismo alcance para la misma persona.
        DB::statement('CREATE UNIQUE INDEX certifabs_familia_viva ON certifabs (user_id, risk_family_id) WHERE revoked_at IS NULL AND risk_family_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX certifabs_activo_vivo  ON certifabs (user_id, asset_id)       WHERE revoked_at IS NULL AND asset_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('certifabs');
    }
};
