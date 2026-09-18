<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Saldo de bienvenida, subcategorías de estudiante y matrículas (§12, §5).
 *
 * **La bienvenida.** El beneficio semanal se abona los lunes. Quien entra por
 * primera vez un martes con su correo de la Universidad se encontraba con cero
 * hasta el lunes siguiente, que es exactamente el día en que ya no vuelve.
 * Ahora cada categoría dice con cuánto nace quien la recibe, y se abona en el
 * acto: al crearse la cuenta o al cambiarle la categoría. Una vez por
 * categoría, con la misma clave de idempotencia que todo lo demás.
 *
 * **Las subcategorías.** Educación Continua matricula gente en bootcamps,
 * cursos y diplomados, y cada programa arranca con un saldo distinto —10, 20 y
 * 30— porque cada uno trae una carga de trabajo distinta al laboratorio.
 * Después, todos reciben el semanal de estudiante. Son categorías de usuario
 * como las demás: tarifa, cupo y dotación las hereda la pantalla que ya existe.
 *
 * **El semanal por categoría, no solo por correo.** Hasta ahora lo decidía el
 * dominio del correo, y un estudiante de bootcamp con Gmail se quedaba fuera.
 * Ahora una categoría puede decir «recibe el semanal», y el dominio sigue
 * valiendo para quien lo tenga.
 *
 * **Las matrículas.** Quién está en qué programa, desde cuándo y quién lo
 * anotó. Es lo que Educación Continua registra, y lo que explica por qué
 * alguien tiene la categoría que tiene.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_categories', function (Blueprint $table) {
            // Con cuanto nace quien recibe esta categoria, en unidades menores.
            // Cero: sin bienvenida.
            $table->bigInteger('welcome_minor')->default(0)->after('allowance_minor');

            // Si recibe el beneficio semanal por ser de esta categoria, tenga
            // el correo que tenga.
            $table->boolean('weekly_benefit')->default(false)->after('welcome_minor');
        });

        Schema::create('matriculas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_category_id')->constrained('user_categories')->restrictOnDelete();

            $table->string('program_name', 160);          // «Bootcamp de IoT 2026-2»
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'ends_on']);
        });

        $menor = (int) config('fabos.currency.minor_units', 100);
        $estudiante = DB::table('user_categories')->where('slug', 'estudiante')->first();

        if (! $estudiante) {
            return;
        }

        // El estudiante general nace con lo mismo que el semanal, y lo recibe.
        DB::table('user_categories')->where('id', $estudiante->id)->update([
            'welcome_minor'  => 8 * $menor,
            'weekly_benefit' => true,
        ]);

        // Las subcategorias heredan todo lo demas del estudiante general: la
        // tarifa, la dotacion, el tramite de cliente. Solo cambia con cuanto
        // nacen.
        $programas = [
            ['estudiante-bootcamp',  'Estudiante · bootcamp',  10],
            ['estudiante-curso',     'Estudiante · curso',     20],
            ['estudiante-diplomado', 'Estudiante · diplomado', 30],
        ];

        foreach ($programas as $i => [$slug, $nombre, $bienvenida]) {
            if (DB::table('user_categories')->where('slug', $slug)->exists()) {
                continue;
            }

            DB::table('user_categories')->insert([
                'slug'               => $slug,
                'name'               => $nombre,
                'position'           => (int) $estudiante->position + 1 + $i,
                'rate_factor'        => $estudiante->rate_factor,
                'allowance_minor'    => $estudiante->allowance_minor,
                'welcome_minor'      => $bienvenida * $menor,
                'weekly_benefit'     => true,
                'max_hours_per_week' => $estudiante->max_hours_per_week,
                'max_days_ahead'     => $estudiante->max_days_ahead,
                'can_reserve'        => $estudiante->can_reserve,
                'is_institutional'   => $estudiante->is_institutional,
                'client_kind'        => $estudiante->client_kind ?? 'estudiante',
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('matriculas');

        Schema::table('user_categories', function (Blueprint $table) {
            $table->dropColumn(['welcome_minor', 'weekly_benefit']);
        });
    }
};
