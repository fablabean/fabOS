<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A qué rubro del presupuesto iría cada deseo (§13).
 *
 * La lista de deseos sabía cuánto cuesta lo que hace falta, pero no contra qué
 * se pagaría. Y el presupuesto no se pide en una cifra: se pide repartido —
 * «Materiales laboratorio», «Herramientas y accesorios», «Licencias y
 * software»—, que es como la Universidad lo asigna y como después hay que
 * ejecutarlo.
 *
 * Sin esto pasaban dos cosas. Al armar el carrito había que acordarse de contra
 * qué presupuesto iba cada cosa, una por una. Y al preparar el año siguiente
 * había un total del que no se podía sacar el reparto, que es lo único que de
 * verdad se entrega.
 *
 * **Se guarda el nombre del rubro, no el presupuesto.** Un deseo es para el año
 * que viene y el presupuesto de ese año todavía no existe: apuntar con una
 * clave foránea al de este año ataría el deseo de 2027 a una fila de 2026, que
 * se lee mal y desaparece si alguien borra ese presupuesto. El nombre es
 * justamente lo que se repite de un año a otro, y es lo que hace de puente.
 *
 * **Y no se inventa un catálogo de rubros.** Las opciones salen de los
 * presupuestos que ya existen: el día que aparezca «Formación externa» estará
 * en la lista sin que nadie despliegue nada. Dos listas que hay que cuadrar
 * entre sí es exactamente lo que se evita.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wishes', function (Blueprint $table) {
            // El nombre del rubro, tal como se llama el presupuesto. Nulo
            // mientras nadie lo haya decidido: apuntar que algo hace falta no
            // deberia exigir saber todavia de que bolsillo sale.
            $table->string('budget_line', 120)->nullable()->after('area_id');

            // Es como se agrupa la lista y como se reparte el ano siguiente.
            $table->index(['target_year', 'budget_line']);
        });
    }

    public function down(): void
    {
        Schema::table('wishes', function (Blueprint $table) {
            $table->dropIndex(['target_year', 'budget_line']);
            $table->dropColumn('budget_line');
        });
    }
};
