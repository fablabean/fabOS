<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que gastó y no estaba en la lista (§12, §13).
 *
 * Al cerrar desde el QR se ofrecen los insumos del área para declarar cuánto
 * se gastó. Pero el catálogo nunca está completo —en impresión 3D hay UN
 * insumo cargado— y quien acaba de usar la máquina no tiene dónde decir «gasté
 * media lija» o «se me fue una boquilla».
 *
 * Con una casilla abierta lo dice igual, y eso es lo que luego hace que el
 * insumo exista: no descuenta inventario ni cobra —no se puede cobrar lo que
 * no tiene precio— pero deja escrito lo que falta por cargar, que es
 * exactamente lo que hoy se pierde.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->string('material_note', 500)->nullable()->after('purpose');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('material_note');
        });
    }
};
