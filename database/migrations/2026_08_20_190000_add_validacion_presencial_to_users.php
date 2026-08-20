<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quién validó a cada persona, y cuándo (§5).
 *
 * `category_confirmed` existía pero no significaba nada: ninguna regla lo
 * consultaba. Ahora la validación es un acto con consecuencias —da acceso— y
 * un acto con consecuencias necesita responsable. Si algún día alguien entró
 * donde no debía, «quién le dio acceso» tiene que tener respuesta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('validated_by_id')->nullable()->after('category_confirmed')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable()->after('validated_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('validated_by_id');
            $table->dropColumn('validated_at');
        });
    }
};
