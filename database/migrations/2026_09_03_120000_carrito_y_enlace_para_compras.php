<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El carrito ya armado y la requisición compartida (§13).
 *
 * Cuando la compra va por Amazon —o por cualquier tienda con carrito— el
 * laboratorio lo deja armado antes de pedir: al área de compras de la
 * Universidad le queda más fácil copiar un enlace que buscar cada cosa. Ese
 * enlace es de la solicitud entera, no de una línea: el carrito ya trae todo.
 *
 * Y la requisición se comparte con un enlace, sin sesión. Quien la recibe en
 * compras no tiene cuenta en fabOS, y mandar un PDF adjunto congela el
 * documento en el correo: si después se corrige una cantidad, el papel que
 * tiene compras es el viejo. El enlace es largo y aleatorio, existe solo
 * cuando alguien decide compartir, y se puede revocar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->string('cart_url', 2000)->nullable()->after('notes');
            $table->string('share_token', 64)->nullable()->unique()->after('cart_url');
            $table->timestamp('shared_at')->nullable()->after('share_token');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropColumn(['cart_url', 'share_token', 'shared_at']);
        });
    }
};
