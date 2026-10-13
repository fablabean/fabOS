<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A quién se le envió copia de la requisición: queda dicho junto al destinatario (§13). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->text('sent_cc')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropColumn('sent_cc');
        });
    }
};
