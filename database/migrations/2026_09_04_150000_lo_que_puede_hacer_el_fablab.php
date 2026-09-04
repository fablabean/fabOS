<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que el Fablab puede hacer por un candidato, en su propia columna (§11).
 *
 * Al evaluar un tablero de convocatoria, la decisión tiene dos partes que no
 * son la misma: por qué se acepta o se descarta, y qué le ofrece el
 * laboratorio si sigue —un prototipo IoT, asistencia con IA, la láser—. Como
 * solo había una caja, la segunda se escribía dentro de la primera con un
 * «Fablab:» delante. Eso funciona para leerlo una vez y no para nada más: ni
 * para filtrar, ni para exportar, ni para pasarlo al proyecto.
 *
 * La columna nueva se rellena con lo que ya se escribió así: lo que va
 * después de «Fablab:» se muda a su sitio y se quita del porqué.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->text('fablab_note')->nullable()->after('evaluation_note');
        });

        foreach (DB::table('candidates')->whereNotNull('evaluation_note')->get(['id', 'evaluation_note']) as $c) {
            if (! preg_match('/^(.*?)\s*\bFablab\s*:\s*(.+)$/isu', $c->evaluation_note, $m)) {
                continue;
            }

            DB::table('candidates')->where('id', $c->id)->update([
                'evaluation_note' => trim($m[1]) !== '' ? trim($m[1]) : null,
                'fablab_note'     => trim($m[2]),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropColumn('fablab_note');
        });
    }
};
