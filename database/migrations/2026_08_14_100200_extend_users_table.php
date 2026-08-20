<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La identidad se ancla al CORREO, no al proveedor (§5). Por eso external_id
 * queda nulo hoy y se llena el dia que se active Entra ID, sin migrar usuarios
 * ni pedir re-registro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('user_category_id')->nullable()->after('email')
                ->constrained()->nullOnDelete();

            $table->string('document_number')->nullable()->unique()->after('user_category_id');
            $table->string('phone')->nullable()->after('document_number');

            // pendiente | activo | suspendido | inactivo
            $table->string('status')->default('pendiente')->after('phone');

            // Identificador del proveedor externo (Entra ID). Nulo mientras se use OTP.
            $table->string('external_id')->nullable()->unique()->after('status');

            // Verificacion por carnet digital EAN: cuando y con que resultado (§5)
            $table->timestamp('identity_verified_at')->nullable()->after('external_id');
            $table->string('identity_verified_via')->nullable()->after('identity_verified_at');

            // La categoria declarada por la persona necesita confirmacion humana
            // salvo que venga verificada en origen por el carnet.
            $table->boolean('category_confirmed')->default(false)->after('identity_verified_via');

            $table->string('locale', 5)->default('es')->after('category_confirmed');
            $table->softDeletes();
        });

        // La contrasena deja de ser obligatoria: el ingreso es por codigo al correo.
        DB::statement('ALTER TABLE users ALTER COLUMN password DROP NOT NULL');
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_category_id');
            $table->dropColumn([
                'document_number', 'phone', 'status', 'external_id',
                'identity_verified_at', 'identity_verified_via',
                'category_confirmed', 'locale', 'deleted_at',
            ]);
        });
    }
};
