<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Las cuentas del libro con el nombre de hoy de su persona (§12).
 *
 * Hasta ahora la cuenta guardaba el nombre del día en que se abrió: la de la
 * cuenta de sistema seguía diciendo «Erick Hansen» después de renombrarse
 * «Fablab Master». Desde ahora el modelo la renombra al cambiar el nombre;
 * esto corrige de una vez las que ya estaban desfasadas.
 *
 * El nombre no entra en el hash de los asientos: la cadena del libro no cambia.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('ledger_accounts')
            ->where('kind', 'usuario')
            ->where('owner_type', 'App\\Models\\User')
            ->orderBy('id')
            ->each(function ($cuenta) {
                $nombre = DB::table('users')->where('id', $cuenta->owner_id)->value('name');

                if ($nombre !== null && $nombre !== $cuenta->name) {
                    DB::table('ledger_accounts')->where('id', $cuenta->id)->update(['name' => $nombre]);
                }
            });
    }

    public function down(): void
    {
        // Los nombres de antes no se guardaron, y no hay a qué volver.
    }
};
