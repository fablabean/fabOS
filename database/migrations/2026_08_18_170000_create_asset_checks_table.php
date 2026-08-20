<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventario cíclico (§7).
 *
 * Un inventario que se levanta una vez al año está desactualizado a los dos
 * meses. La idea es la contraria: escanear el QR de una gaveta y confirmar en
 * treinta segundos lo que debería estar ahí. Cada revisión queda registrada,
 * así que se puede saber qué se revisó, cuándo y quién lo hizo — y qué lleva
 * demasiado tiempo sin mirarse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained();

            // presente | ausente | movido
            $table->string('result');
            $table->string('note')->nullable();
            $table->timestampTz('checked_at')->useCurrent();
            $table->timestamps();

            $table->index(['asset_id', 'checked_at']);
            $table->index(['location_id', 'checked_at']);
        });

        // Última revisión, para saber qué lleva tiempo sin mirarse.
        Schema::table('assets', function (Blueprint $table) {
            $table->timestampTz('last_checked_at')->nullable()->after('warranty_until');
        });
    }

    public function down(): void
    {
        Schema::table('assets', fn (Blueprint $t) => $t->dropColumn('last_checked_at'));
        Schema::dropIfExists('asset_checks');
    }
};
