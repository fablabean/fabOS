<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dos cosas que le faltaban al presupuesto (§13).
 *
 * **El ejecutado de antes.** El sistema deriva lo ejecutado de las solicitudes
 * de compra, que es lo correcto: un «disponible» editable a mano es lo que hace
 * que a mitad de año nadie sepa cuánto queda. Pero el año arrancó antes que el
 * sistema, y lo gastado en enero no tiene solicitud que lo respalde. Sin poder
 * anotarlo, el presupuesto enseña como disponible una plata que ya no está, que
 * es peor que cualquier campo editable.
 *
 * Se guarda **aparte** de lo derivado y con su explicación obligatoria: así se
 * distingue siempre lo que el sistema sabe de lo que alguien afirmó, y de aquí
 * en adelante manda la solicitud de compra.
 *
 * **El presupuesto de venta.** No todo presupuesto es para gastar: el
 * laboratorio también tiene una meta de ingresos, y la plata que entra por
 * ventas va contra ella. Es la misma idea al revés —un monto de referencia y un
 * acumulado que se le acerca—, y meterla en la misma tabla evita inventar una
 * pantalla nueva para leer lo mismo en el otro sentido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            // gasto | venta
            $table->string('kind')->default('gasto')->after('name');

            // Lo que ya se había movido antes de que existiera el sistema.
            $table->bigInteger('opening_executed')->default(0)->after('amount');
            $table->text('opening_note')->nullable()->after('opening_executed');
        });
    }

    public function down(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            $table->dropColumn(['kind', 'opening_executed', 'opening_note']);
        });
    }
};
