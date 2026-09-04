<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La lista de espera: evaluado, ni aceptado ni descartado (§11).
 *
 * Un candidato con nota y sin decisión no está «sin evaluar»: alguien ya lo
 * miró y lo dejó en espera, que es una decisión —no se descarta, tampoco se
 * aprueba todavía—. Con dos únicas salidas, eso se guardaba como pendiente y
 * el lote decía que faltaba evaluarlo, cuando lo que faltaba era otra cosa.
 *
 * No hay columna nueva: es un estado más. Lo que ya tenía nota y seguía
 * pendiente se pasa a espera, que es lo que era.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('candidates')
            ->where('status', 'pendiente')
            ->whereNotNull('score')
            ->update(['status' => 'espera']);
    }

    public function down(): void
    {
        DB::table('candidates')->where('status', 'espera')->update(['status' => 'pendiente']);
    }
};
