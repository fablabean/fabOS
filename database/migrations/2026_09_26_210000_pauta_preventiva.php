<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * La pauta preventiva: planes que cubren un grupo de equipos (§8).
 *
 * Un plan cubría un equipo o una familia de riesgo entera. Para decir «estos
 * doce activos fijos se revisan cada mes, estos veinte cada seis» hacía falta
 * un plan por equipo. Ahora un plan tiene sus equipos elegidos a mano, una
 * fecha de primera revisión —para no abrir cuarenta órdenes la misma mañana—
 * y, si viene de la pauta, la frecuencia de la que es (mensual, semestral…).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_plans', function (Blueprint $table) {
            $table->date('starts_on')->nullable()->after('every_usage_minutes');
            // La franja de la pauta a la que pertenece, si es de la pauta.
            $table->string('pauta', 20)->nullable()->unique()->after('name');
        });

        Schema::create('asset_maintenance_plan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maintenance_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['maintenance_plan_id', 'asset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_maintenance_plan');

        Schema::table('maintenance_plans', function (Blueprint $table) {
            $table->dropUnique(['pauta']);
            $table->dropColumn(['starts_on', 'pauta']);
        });
    }
};
