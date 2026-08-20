<?php

use App\Models\Certifab;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Código público de verificación (§9).
 *
 * Permite que cualquiera —otra universidad, un empleador, otro Fab Lab—
 * confirme que una habilitación es auténtica sin llamar a la EAN.
 *
 * Es aleatorio y no correlativo: con un código no se pueden adivinar los demás,
 * ni deducir cuántas certificaciones se han emitido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certifabs', function (Blueprint $table) {
            $table->string('public_code', 16)->nullable()->unique()->after('id');
        });

        Certifab::whereNull('public_code')->get()->each(
            fn (Certifab $c) => $c->forceFill(['public_code' => Str::upper(Str::random(10))])->save()
        );
    }

    public function down(): void
    {
        Schema::table('certifabs', function (Blueprint $table) {
            $table->dropColumn('public_code');
        });
    }
};
