<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El reporte de horas extras lo hace cada quien (§5).
 *
 * Cada colaborador saca su ADF-404-f4 desde su cuenta, lo firma y lo manda a
 * novedades de nómina. Para eso hace falta:
 *
 * - **La fecha de corte de cada mes**, que no es fija: nómina la mueve entre el
 *   14 y el 17. Un mes sin fecha escrita usa el día de corte de la
 *   configuración.
 * - **El reporte**: qué jornadas llevó, cuándo se generó y el archivo tal como
 *   se descargó, para volver a bajarlo igual.
 * - **En la jornada, el reporte que la llevó.** Una jornada va en un solo
 *   reporte: eso es lo que impide pagarla dos veces.
 * - **En la persona, lo que el formato pide** —tipo de contrato, área, jefe
 *   inmediato— y si su contrato es flexible: entonces no hace horas extras,
 *   lo del sábado se compensa con tiempo en la semana siguiente, y solo se le
 *   reconoce el recargo nocturno.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('tipo_contrato', 20)->nullable();
            $table->boolean('contrato_flexible')->default(false);
            $table->string('area_de_nomina')->nullable();
            $table->string('jefe_inmediato')->nullable();
        });

        Schema::create('cortes_de_nomina', function (Blueprint $table) {
            $table->id();
            $table->date('mes')->unique();      // el primero del mes
            $table->date('fecha');              // el día de corte de ese mes
            $table->timestamps();
        });

        Schema::create('reportes_de_extras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('corte');
            // «fabos» lo generó aquí; «por_fuera», jornadas que ya se habían
            // reportado a nómina antes de esto, para que no salgan pendientes.
            $table->string('origen', 20)->default('fabos');
            $table->unsignedInteger('minutos')->default(0);
            $table->unsignedInteger('diurnas')->default(0);
            $table->unsignedInteger('nocturnas')->default(0);
            $table->unsignedInteger('diurnas_festivas')->default(0);
            $table->unsignedInteger('nocturnas_festivas')->default(0);
            $table->json('datos')->nullable();
            $table->string('archivo_path')->nullable();
            $table->foreignId('generado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('anulado_at')->nullable();
            $table->foreignId('anulado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'corte']);
        });

        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->foreignId('reporte_id')->nullable()->constrained('reportes_de_extras')->nullOnDelete();
            $table->text('actividades')->nullable();
            $table->date('compensa_el')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reporte_id');
            $table->dropColumn(['actividades', 'compensa_el']);
        });
        Schema::dropIfExists('reportes_de_extras');
        Schema::dropIfExists('cortes_de_nomina');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['tipo_contrato', 'contrato_flexible', 'area_de_nomina', 'jefe_inmediato']);
        });
    }
};
