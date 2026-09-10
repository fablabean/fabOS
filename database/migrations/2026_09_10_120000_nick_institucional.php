<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El nick institucional (§5).
 *
 * Lo que va antes de la arroba en un correo de la Universidad no se repite:
 * «ehansen» es una sola persona. Se guarda aparte, solo para quien tiene
 * correo institucional, y sirve para dos cosas: entrar escribiendo solo el
 * nick, y reconocer a la persona por un dato exacto en vez de por su nombre
 * completo, que es débil.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('nick', 120)->nullable()->unique()->after('email');
        });

        $dominio = strtolower(trim((string) config('fabos.identity.institutional_domain')));

        if ($dominio === '') {
            return;
        }

        // Los que ya están: el nick sale de su correo.
        foreach (DB::table('users')->select('id', 'email')->whereRaw('LOWER(email) LIKE ?', ['%@' . $dominio])->cursor() as $u) {
            $nick = strtolower(trim(explode('@', $u->email, 2)[0]));

            if ($nick === '' || DB::table('users')->where('nick', $nick)->exists()) {
                continue;
            }

            DB::table('users')->where('id', $u->id)->update(['nick' => $nick]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['nick']);
            $table->dropColumn('nick');
        });
    }
};
