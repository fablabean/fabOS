<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La firma del solicitante en la solicitud de cotización (§13).
 *
 * El ADF-228-f5 lleva «Firma del Solicitante», y compras no lo tramita sin
 * ella. Se dibuja en pantalla, como en las actas, antes de enviarla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->string('signature_path')->nullable()->after('requires_art');
            $table->timestamp('signed_at')->nullable()->after('signature_path');
            $table->foreignId('signed_by')->nullable()->after('signed_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('signed_by');
            $table->dropColumn(['signature_path', 'signed_at']);
        });
    }
};
