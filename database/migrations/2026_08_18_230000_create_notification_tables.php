<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comunicaciones (§15).
 *
 * Tres decisiones:
 *
 *  - **Las plantillas viven en la base de datos, no en el código.** Quien
 *    coordina el laboratorio tiene que poder corregir una frase mal redactada
 *    sin esperar un despliegue. El sistema decide *cuándo* se avisa; el texto
 *    es de quien atiende a la gente.
 *  - **Todo envío queda registrado.** «¿Le avisaron?» es la pregunta que más se
 *    repite cuando algo sale mal, y sin bitácora la respuesta es una opinión.
 *  - **Se puede dejar de recibir lo prescindible, no lo esencial.** Un
 *    recordatorio es cortesía; que te avisen que tu equipo entró a
 *    mantenimiento no lo es.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();          // reserva.confirmada
            $table->string('name');
            $table->string('channel')->default('email');   // email | whatsapp
            $table->string('subject')->nullable();
            $table->text('body');

            // Si es esencial, no se puede desactivar ni dejar de recibir.
            $table->boolean('is_essential')->default(false);
            $table->boolean('is_active')->default(true);

            // Variables disponibles, para mostrarlas al editar la plantilla.
            $table->json('variables')->nullable();
            $table->string('description')->nullable();

            $table->timestamps();
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->boolean('email')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'key']);
        });

        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('key');
            $table->string('channel')->default('email');
            $table->string('to');                     // a donde se envio de verdad
            $table->string('subject')->nullable();
            $table->text('body')->nullable();

            // enviado | omitido | fallido
            $table->string('status');
            $table->string('reason')->nullable();     // por que se omitio o fallo

            $table->nullableMorphs('reference');
            $table->timestampTz('sent_at')->nullable();
            $table->timestamps();

            $table->index(['key', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notification_templates');
    }
};
