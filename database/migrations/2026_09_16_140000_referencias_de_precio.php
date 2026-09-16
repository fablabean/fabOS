<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De dónde salió el precio que cobramos (§14).
 *
 * El precio de un servicio se puede calcular desde el costo —material, tiempo
 * de máquina, margen— y el sistema ya lo hace. Lo que ese cálculo no contesta
 * es la pregunta que llega después: **«¿y eso está caro?»**. La respuesta no
 * sale de nuestra hoja de costos; sale de lo que cobra el de la esquina.
 *
 * Esto guarda esa segunda mitad: qué cobra el mercado, quién lo cobra, y
 * cuándo se miró. Tres cosas que importan por separado:
 *
 *  · **Quién y dónde**, para poder volver a mirarlo. Una cifra sin fuente es
 *    un rumor con decimales.
 *  · **Cuándo se consultó.** Un precio de referencia de hace tres años no
 *    respalda nada, y sin fecha no hay manera de saber que caducó.
 *  · **En pesos**, no en FabCoins. El mercado cotiza en pesos; convertirlo al
 *    guardarlo escondería el dato original detrás de una tasa que cambia.
 *
 * Varias referencias por cosa, a propósito: un solo competidor es una
 * anécdota, tres son un rango. Y el rango es lo que de verdad sirve para
 * defender una tarifa ante quien pregunta.
 *
 * Es polimórfica como los escalones de precio, y por lo mismo: un servicio y
 * un insumo tienen la misma pregunta y no hay razón para dos tablas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referencias_de_precio', function (Blueprint $table) {
            $table->id();

            $table->morphs('priceable');

            // Quién lo cobra. Puede ser un competidor, un proveedor o «nuestro
            // propio cálculo de costos», que también es una referencia válida.
            $table->string('fuente');
            $table->string('url')->nullable();

            // En pesos enteros, como el resto del dinero real del sistema.
            $table->bigInteger('precio_pesos');

            // En qué unidad cobra ESA fuente, que no siempre es la nuestra:
            // uno cobra por gramo y otro por pieza, y compararlos sin decir la
            // unidad es como no haber consultado nada.
            $table->string('unidad');

            $table->date('consultado_el');
            $table->text('notas')->nullable();

            $table->timestamps();

            $table->index(['priceable_type', 'priceable_id', 'consultado_el']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referencias_de_precio');
    }
};
