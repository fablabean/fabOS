<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La lista de deseos del laboratorio (§13).
 *
 * Compras solo sabía de cosas que ya se habían decidido: se abre un carrito, se
 * aprueba contra un presupuesto y se recibe. Pero antes de eso hay una
 * conversación que no tenía dónde vivir —«nos hace falta una fresadora»,
 * «habría que probar resina flexible»— y que se sostenía en un chat, un cuaderno
 * o la memoria de quien coordina. Se perdía dos veces: cuando aparecía plata a
 * mitad de año y nadie recordaba qué se quería, y cuando la Universidad pedía el
 * presupuesto del año siguiente y la cifra se inventaba desde cero.
 *
 * **Un deseo no es una solicitud.** No compromete plata, no exige presupuesto y
 * puede no tener precio todavía. De aquí salen los dos caminos que faltaban: se
 * seleccionan deseos y se arma un carrito cuando hay con qué, y la lista entera
 * de un año se suma por área para proponer el presupuesto de ese año.
 *
 * **No hay «listas», hay deseos sueltos.** La lista es el filtro: año destino
 * más área. Copiar la lista de un año al siguiente es exactamente como acaban
 * existiendo tres listas que dicen cosas distintas de lo mismo; aquí se cambia
 * el año y ya.
 *
 * **El estado no se guarda, se deriva** del enlace a la línea de compra, igual
 * que el saldo del presupuesto se deriva de las solicitudes. Un estado escrito
 * habría que actualizarlo por tres caminos distintos —cancelar la solicitud,
 * quitar la línea, borrar la solicitud entera— y el que se olvidara dejaría un
 * deseo marcado «en solicitud» que nunca vuelve a la lista y que nadie vuelve a
 * mirar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wishes', function (Blueprint $table) {
            $table->id();

            // El ano DESTINO, no el ano en que se apunto: un deseo que no
            // alcanzo se pasa al siguiente cambiando esta cifra, y por eso no
            // se llama `year` a secas —se leeria como «cuando se escribio»—.
            $table->unsignedSmallInteger('target_year');

            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();

            // Si repone algo del catalogo, hereda su unidad y su ultimo costo, y
            // la linea que salga de aqui entrara sola al inventario al recibirse.
            // Casi siempre es nulo: un deseo suele ser algo que el laboratorio
            // TODAVIA no tiene, y obligar a crearle ficha antes de desearlo es
            // lo que llena el catalogo de cosas que nunca llegaron.
            $table->foreignId('supply_id')->nullable()->constrained()->nullOnDelete();

            $table->string('description');

            // Para que se necesita. Esto es lo que se lee el dia que hay que
            // defender el presupuesto del ano que viene.
            $table->string('justification')->nullable();

            $table->string('unit')->default('unidad');
            $table->decimal('quantity', 12, 3)->default(1);

            // Estimado unitario en pesos enteros, o nulo si nadie lo ha
            // cotizado todavia. Nulo y no cero: un cero suma bien y miente, y el
            // resumen del ano contaria como presupuestado algo que nadie sabe
            // cuanto cuesta.
            $table->bigInteger('unit_price')->nullable();

            $table->string('priority', 16)->default('media');  // alta · media · baja
            $table->string('reference_url')->nullable();       // el enlace al producto

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            // En que linea de solicitud termino. `nullOnDelete` a proposito: si
            // alguien quita esa linea del carrito, o borra la solicitud entera
            // —sus lineas se van en cascada con ella—, el deseo vuelve SOLO a la
            // lista en vez de quedarse apuntando a un fantasma.
            $table->foreignId('purchase_request_item_id')->nullable()
                ->constrained('purchase_request_items')->nullOnDelete();

            // La unica decision que si se guarda: «esto no se va a comprar».
            // Con motivo, porque un deseo que desaparece sin explicacion se
            // vuelve a apuntar el mes siguiente.
            $table->timestampTz('discarded_at')->nullable();
            $table->string('discarded_reason')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            // La «lista» es este par. El indice es el de la consulta real.
            $table->index(['target_year', 'area_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wishes');
    }
};
