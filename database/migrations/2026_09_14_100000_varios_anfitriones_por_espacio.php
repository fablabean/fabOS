<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un espacio puede tener varios anfitriones (§7).
 *
 * Con uno solo, cuando esa persona no está —el almuerzo, un día libre— la sala
 * se queda sin nadie preferente y quien recibe sale del reparto general. En el
 * laboratorio real eso pasaba todos los días: una persona era anfitriona de
 * diez de los doce espacios, y sus reservas de mediodía caían siempre en otro,
 * porque su descanso es justo a esa hora.
 *
 * Con varios, el sistema elige entre ellos por carga, y solo sale del grupo
 * cuando ninguno puede.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('space_user', function (Blueprint $table) {
            $table->foreignId('space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // La misma persona dos veces en la misma sala no significa nada.
            $table->primary(['space_id', 'user_id']);
        });

        /*
         * Lo que ya estaba asignado se conserva.
         *
         * Migrar y perder por el camino a quien recibía en cada sala dejaría
         * doce espacios sin anfitrión y nadie se daría cuenta hasta la
         * siguiente reserva.
         */
        $ahora = now();

        $anfitriones = DB::table('spaces')
            ->whereNotNull('host_id')
            ->get(['id', 'host_id'])
            ->map(fn ($espacio) => [
                'space_id'   => $espacio->id,
                'user_id'    => $espacio->host_id,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ])
            ->all();

        if ($anfitriones !== []) {
            DB::table('space_user')->insert($anfitriones);
        }

        // Y se va la columna: dos sitios para el mismo dato acaban discrepando.
        Schema::table('spaces', function (Blueprint $table) {
            $table->dropConstrainedForeignId('host_id');
        });
    }

    public function down(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            $table->foreignId('host_id')->nullable()->after('shares_seats')
                ->constrained('users')->nullOnDelete();
        });

        // Solo cabe uno: se devuelve el primero de cada sala. Lo que se pierde
        // al volver atrás son los demás, y es inevitable.
        foreach (DB::table('space_user')->orderBy('space_id')->orderBy('user_id')->get() as $fila) {
            DB::table('spaces')
                ->where('id', $fila->space_id)
                ->whereNull('host_id')
                ->update(['host_id' => $fila->user_id]);
        }

        Schema::dropIfExists('space_user');
    }
};
