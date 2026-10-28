<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bloqueos de reservas programados, con aviso previo.
 *
 * El bloqueo era uno solo, guardado en un ajuste, y arrancaba en el momento
 * de activarlo: servía para una emergencia, no para «el viernes no se abre».
 * Ahora cada bloqueo tiene su inicio y su fin —el viernes entero, o de tal
 * hora a tal otra la próxima semana— y desde cuándo se avisa en el sitio.
 * Puede haber varios a la vez.
 *
 * El bloqueo que estuviera activo pasa a la tabla tal cual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bloqueos_de_reservas', function (Blueprint $table) {
            $table->id();
            $table->text('motivo');
            $table->timestampTz('desde');
            // Vacío: hasta que alguien lo levante.
            $table->timestampTz('hasta')->nullable();
            $table->timestampTz('avisar_desde');
            $table->boolean('dias_completos')->default(false);
            $table->string('por')->nullable();
            $table->timestampTz('levantado_at')->nullable();
            $table->timestamps();

            $table->index(['levantado_at', 'desde']);
        });

        $e = \App\Models\Setting::get('reservas.bloqueo');

        if (is_array($e) && ($e['activo'] ?? false) && (empty($e['hasta']) || strtotime($e['hasta']) > time())) {
            $desde = $e['desde'] ?? now()->toIso8601String();

            DB::table('bloqueos_de_reservas')->insert([
                'motivo'       => trim((string) ($e['motivo'] ?? '')) ?: 'Por una situación en el laboratorio.',
                'desde'        => $desde,
                'hasta'        => $e['hasta'] ?: null,
                'avisar_desde' => $desde,
                'por'          => $e['por'] ?? null,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bloqueos_de_reservas');
    }
};
