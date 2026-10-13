<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La lista de proveedores (§13).
 *
 * El proveedor se escribía entero en cada solicitud de compra: la cuarta vez
 * que se le compra a la misma empresa, alguien vuelve a buscar el NIT en un
 * correo. Aquí queda una vez, y las solicitudes lo eligen.
 *
 * La solicitud conserva su propia copia de los datos: una requisición que
 * alguien aprobó no cambia porque después se corrija la dirección del
 * proveedor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 180);
            $table->string('tax_id', 40)->nullable();
            // El NIT sin puntos, guiones ni espacios: para reconocer al mismo
            // proveedor escrito de dos maneras.
            $table->string('tax_id_clave', 40)->nullable()->index();
            $table->string('address', 200)->nullable();
            $table->string('contact', 160)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email', 160)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
        });

        // Los que ya se escribieron en alguna solicitud entran a la lista.
        DB::table('purchase_requests')
            ->whereNotNull('supplier_name')
            ->where('supplier_name', '<>', '')
            ->orderBy('id')
            ->get(['id', 'supplier_name', 'supplier_tax_id', 'supplier_address', 'supplier_contact'])
            ->each(function ($s) {
                $proveedor = \App\Models\Supplier::anotar([
                    'name'    => $s->supplier_name,
                    'tax_id'  => $s->supplier_tax_id,
                    'address' => $s->supplier_address,
                    'contact' => $s->supplier_contact,
                ]);

                DB::table('purchase_requests')->where('id', $s->id)->update(['supplier_id' => $proveedor?->id]);
            });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_id');
        });

        Schema::dropIfExists('suppliers');
    }
};
