<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El laboratorio entero como espacio, y quién acompaña una reserva (§7).
 *
 * Un recorrido ocupa todo el laboratorio sin cerrarlo: caben treinta
 * personas a la vez, en grupos de quince, y las máquinas siguen trabajando.
 * Eso no cabía en el modelo, donde reservar un espacio es tenerlo en
 * exclusiva: dos recorridos a la misma hora se pisaban en la base antes de
 * llegar a ninguna regla.
 *
 * Tres cosas:
 *
 *  · Un espacio marcado como **todo el laboratorio**. Se siembra aquí y no
 *    en un seeder porque el despliegue migra pero no siembra: sin la fila,
 *    la pantalla ofrecería un recorrido que no existe.
 *  · La exclusión de solapes **deja pasar los recorridos**. Cuántas personas
 *    caben a la vez lo decide el servicio, que es quien sabe sumar; la base
 *    solo sigue impidiendo que dos reservas exclusivas se pisen.
 *  · **Quién acompaña**, en su tabla. Hasta ahora había un supervisor, uno
 *    solo; un recorrido de treinta lo acompañan dos o tres del equipo, y una
 *    charla puede no acompañarla nadie.
 */
return new class extends Migration
{
    private const BLOQUEANTES = ['confirmada', 'en_curso'];

    public function up(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            $table->boolean('es_todo')->default(false)->after('is_production_space');
        });

        Schema::create('reservation_companions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['reservation_id', 'user_id']);
        });

        $bloqueantes = "'" . implode("','", self::BLOQUEANTES) . "'";

        DB::statement('ALTER TABLE reservations DROP CONSTRAINT IF EXISTS reservations_sin_traslape');
        DB::statement("
            ALTER TABLE reservations
            ADD CONSTRAINT reservations_sin_traslape
            EXCLUDE USING gist (
                reservable_type WITH =,
                reservable_id   WITH =,
                period          WITH &&
            )
            WHERE (status IN ({$bloqueantes}) AND mode <> 'recorrido')
        ");

        DB::table('spaces')->updateOrInsert(
            ['slug' => 'todo-el-laboratorio'],
            [
                'name'                => 'Todo el laboratorio',
                'type'                => 'fisico',
                'capacity'            => 30,
                'is_reservable'       => true,
                'is_production_space' => false,
                'es_todo'             => true,
                'created_at'          => now(),
                'updated_at'          => now(),
            ],
        );
    }

    public function down(): void
    {
        $bloqueantes = "'" . implode("','", self::BLOQUEANTES) . "'";

        DB::statement('ALTER TABLE reservations DROP CONSTRAINT IF EXISTS reservations_sin_traslape');
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

        Schema::dropIfExists('reservation_companions');

        Schema::table('spaces', function (Blueprint $table) {
            $table->dropColumn('es_todo');
        });
    }
};
