<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enlaces cortos con QR, y su rastro (§7, §21).
 *
 * El laboratorio pega códigos en carteles, en piezas y en fichas de curso, y
 * hasta ahora cada uno llevaba pegada la direccion larga y definitiva: si
 * cambiaba la pagina, el cartel impreso quedaba mintiendo, y no habia forma de
 * saber si alguien lo habia escaneado alguna vez.
 *
 * Un enlace corto arregla las dos cosas: el codigo impreso no cambia nunca y a
 * donde apunta se edita cuando haga falta, y cada visita deja rastro.
 *
 * SOBRE EL RASTRO. Se guarda lo justo para responder «¿esto sirvio?»: cuando,
 * de donde venia y si fue telefono u ordenador. NO se guarda la direccion IP ni
 * se pone una cookie: para contar cuantas veces se escaneo un cartel no hace
 * falta saber quien lo escaneo, y lo que no se guarda no se puede filtrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('short_links', function (Blueprint $table) {
            $table->id();

            // El codigo que va impreso. Corto porque se teclea a mano cuando la
            // camara no lee, y unico porque es la direccion.
            $table->string('code', 32)->unique();

            $table->string('name');
            $table->text('target');
            $table->text('notes')->nullable();

            $table->boolean('is_active')->default(true);
            // Un cartel de un evento que ya paso deja de llevar a ningun sitio
            // sin que nadie tenga que acordarse de apagarlo.
            $table->timestamp('expires_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('short_link_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('short_link_id')->constrained()->cascadeOnDelete();
            $table->timestamp('visited_at');

            // Solo el dominio de donde venia, no la direccion entera: sirve
            // para distinguir «llego por Instagram» de «lo escaneo en el
            // pasillo», que es la pregunta real.
            $table->string('source', 120)->nullable();
            $table->string('device', 20)->nullable();

            $table->index(['short_link_id', 'visited_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('short_link_visits');
        Schema::dropIfExists('short_links');
    }
};
