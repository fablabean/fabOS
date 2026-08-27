<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El impuesto, por solicitud (§13).
 *
 * El sistema le sumaba el IVA a todo, con la tasa general configurada. Es lo
 * correcto para comprar material —compras trabaja con el valor con IVA, y
 * presentar el subtotal a secas hace que el presupuesto parezca alcanzar para
 * más de lo que alcanza—, pero no todo lo que se pide lleva ese IVA: unos
 * honorarios, un servicio exento, una compra a un régimen simplificado.
 *
 * El efecto de no poder decirlo es peor que el de no calcularlo: quien escribe
 * un valor ve otro más alto en el listado, no entiende de dónde salió, y deja
 * de fiarse de la cifra. Y una cifra en la que no se confía no se usa para
 * decidir.
 *
 * Nulo significa «la del laboratorio»: así, cambiar la tasa general el día que
 * cambie la ley arrastra a todo lo que no dijo otra cosa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->decimal('tax_rate', 5, 4)->nullable()->after('budget_id');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropColumn('tax_rate');
        });
    }
};
