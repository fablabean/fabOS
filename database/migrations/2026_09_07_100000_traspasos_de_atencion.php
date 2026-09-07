<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pasarle a otra persona del equipo lo que a uno le toca atender (§10).
 *
 * Una asesoria o un acompanamiento quedan a nombre de alguien concreto. Si ese
 * dia no puede, hasta ahora tenia que escribirle a la coordinacion para que lo
 * reasignara a mano. Con esto se lo propone directamente a un companero, y el
 * companero decide: nadie recibe una asesoria en su agenda sin haber dicho
 * que si.
 *
 * Es una tabla aparte y no un campo en la reserva porque la propuesta tiene
 * vida propia —esta pendiente, se acepta, se rechaza, se retira— y esa
 * historia importa: quien pasa muchas y quien las recibe se ve aqui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('to_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('pendiente');
            $table->string('note', 500)->nullable();
            $table->string('answer', 500)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['to_user_id', 'status']);
            $table->index(['reservation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_transfers');
    }
};
