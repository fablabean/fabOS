<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedir prestada una herramienta no exige estar habilitado (§7).
 *
 * El certifab dice que alguien te vio operar una máquina. Un multímetro, una
 * fuente de voltaje o unas gafas de realidad virtual no son una máquina: se
 * piden, se usan y se devuelven, y exigir un curso para llevarse un taladro
 * solo consigue que nadie lo pida.
 *
 * La excepción NO puede ir por familia de riesgo, que es donde vive hoy la
 * exigencia, porque las familias están mezcladas: «Máquina mayor» tiene seis
 * máquinas fijas y una pulidora, «Realidad virtual» una fija y trece gafas,
 * «Electrónica y soldadura» una fija y cuatro cautines. Quitarla por familia
 * abriría también las máquinas.
 *
 * Va por equipo, con tres respuestas:
 *
 *  · **nulo** — lo que diga la regla: una herramienta no lo exige (si así se
 *    decidió en Finanzas → Cobros), una máquina sí.
 *  · **sí** — lo exige siempre, aunque sea herramienta. Es lo que se le marca
 *    al robot, que se presta pero no se le entrega a cualquiera.
 *  · **no** — no lo exige nunca.
 *
 * Esto responde además a «el robot debería ser herramienta y activo fijo a la
 * vez»: lo que se quería del activo fijo era que siguiera exigiendo
 * habilitación, y eso se dice aquí sin partir en dos la naturaleza del equipo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->boolean('exige_certifab')->nullable()->after('unattended_use');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn('exige_certifab');
        });
    }
};
