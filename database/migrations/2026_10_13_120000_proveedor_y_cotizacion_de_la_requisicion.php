<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A quién se le compra, y con qué cotización (§13).
 *
 * El proveedor iba escrito en las observaciones —«tap publicidad, fulano, nit
 * tal»—, cada vez de una forma, y compras tenía que pescarlo del texto. Y la
 * cotización formal viajaba aparte, por correo, sin quedar junto a la
 * requisición que la justifica.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->string('supplier_name', 180)->nullable();
            $table->string('supplier_tax_id', 40)->nullable();
            $table->string('supplier_address', 200)->nullable();
            $table->string('supplier_contact', 160)->nullable();
            $table->string('quote_path')->nullable();
            $table->string('quote_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropColumn([
                'supplier_name', 'supplier_tax_id', 'supplier_address', 'supplier_contact',
                'quote_path', 'quote_name',
            ]);
        });
    }
};
