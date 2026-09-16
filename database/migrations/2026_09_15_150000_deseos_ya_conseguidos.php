<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un deseo que se consiguió por fuera del carrito (§13).
 *
 * Hasta ahora un deseo tenía dos finales: pasar por una solicitud de compra y
 * recibirse —«Comprado»—, o decidir que no —«Descartado»—. Falta el tercero, y
 * es el más común en un laboratorio: **llegó de otra manera**. Lo donaron, lo
 * tenía otra área, se compró directo en una caja menor, apareció en una bodega.
 *
 * Sin un sitio donde decir eso, el deseo se queda para siempre en la lista
 * pidiendo algo que ya está en la sala —y sumando al presupuesto del año que
 * viene—, o se borra, y entonces desaparece la única prueba de que alguna vez
 * hizo falta.
 *
 * Por qué una marca propia y no reutilizar las que hay:
 *
 *  · **«Comprado» no se puede tocar a mano**, y en eso está su valor: se deriva
 *    de la línea de compra recibida, así que cuadra con compras y con el libro.
 *    Un botón que lo escribiera a dedo convertiría ese dato en una opinión.
 *  · **«Descartado» significa que se decidió que NO.** Meter ahí lo que sí se
 *    consiguió dejaría el histórico diciendo justo lo contrario de lo que pasó,
 *    y ese histórico es con lo que se argumenta el presupuesto siguiente.
 *
 * Con nota, y no solo con fecha: «cómo llegó» es la pregunta que se hace quien
 * mire esto dentro de un año, y sin respuesta se vuelve a discutir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wishes', function (Blueprint $table) {
            $table->timestamp('fulfilled_at')->nullable();
            $table->foreignId('fulfilled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('fulfilled_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('wishes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fulfilled_by');
            $table->dropColumn(['fulfilled_at', 'fulfilled_note']);
        });
    }
};
