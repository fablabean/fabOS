<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Libro contable de doble partida para los FabCoins (§12).
 *
 * El saldo NO se almacena: se deriva de los asientos. Una columna `saldo` que
 * se suma y se resta acaba, tarde o temprano, en un descuadre que nadie puede
 * reconciliar porque no queda rastro de cómo llegó ahí.
 *
 * Los importes van en ENTEROS —unidades menores— porque los decimales
 * flotantes acumulan errores de redondeo: cien operaciones de 0,1 no dan 10.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();          // usuario:12, sistema:emision
            $table->string('name');

            // Cuentas de persona, de proyecto o del sistema.
            $table->string('owner_type')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->string('kind');                    // usuario | proyecto | sistema
            $table->string('currency', 8)->default('FBC');
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
        });

        Schema::create('ledger_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('kind');                    // dotacion | recarga | reserva | ...

            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            // Un doble clic no debe cobrar dos veces.
            $table->string('idempotency_key')->nullable()->unique();

            $table->string('memo')->nullable();
            $table->timestampTz('occurred_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // Cada transacción encadena el hash de la anterior: alterar una
            // rompe la cadena de todas las siguientes, y se nota.
            $table->string('prev_hash', 64)->nullable();
            $table->string('hash', 64);

            $table->timestamps();

            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ledger_transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ledger_account_id')->constrained();
            $table->char('direction', 1);              // D = débito, C = crédito
            $table->bigInteger('amount_minor');
            $table->timestamps();

            $table->index(['ledger_account_id', 'direction']);
        });

        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT entries_importe_positivo CHECK (amount_minor > 0)');
        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT entries_direccion CHECK (direction IN ('D','C'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('ledger_transactions');
        Schema::dropIfExists('ledger_accounts');
    }
};
