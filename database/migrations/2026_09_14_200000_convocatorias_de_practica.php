<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Convocatorias de práctica y quién se postula (§5).
 *
 * Cada semestre llegan estudiantes que quieren hacer su práctica en el
 * laboratorio: unos de la propia Universidad, otros de fuera. Llegaban por
 * correo, por WhatsApp y por el pasillo, y se decidía con las hojas de vida
 * repartidas entre tres bandejas de entrada. Cuando alguien preguntaba por qué
 * no quedó tal persona, nadie tenía la respuesta escrita.
 *
 * **Se evalúa por tandas, no de uno en uno.** Una convocatoria es el semestre
 * —«Prácticas 2026-1»— y se decide con la tanda entera delante, que es como se
 * compara de verdad: evaluar abriendo fichas sueltas hace que la tercera se
 * juzgue con otro criterio que la primera. Es la misma idea de los lotes de
 * candidatos de proyectos (§11), aplicada a personas.
 *
 * **Un postulante no es una cuenta.** Vive aparte a propósito: darle usuario y
 * rol a quien probablemente no quede llena el sistema de gente que nunca
 * entró. Se convierte **cuando se acepta**, y ni un minuto antes.
 *
 * **Interno o externo no se escribe: se deriva del correo.** Un correo del
 * dominio de la Universidad prueba pertenencia; una casilla marcada a mano solo
 * prueba que alguien la marcó. La universidad de quien viene de fuera sí se
 * anota, porque hace falta para el convenio.
 *
 * **Y aquí hay datos personales de menores de edad potenciales y de terceros**
 * (Ley 1581 de 2012): quien se postula desde el sitio autoriza el tratamiento
 * en el mismo acto, y esa autorización queda con su fecha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internship_calls', function (Blueprint $table) {
            $table->id();

            $table->string('name');                         // Prácticas 2026-1
            $table->string('slug')->unique();               // para la direccion publica
            $table->string('period', 20)->nullable();       // 2026-1 · verano
            $table->text('description')->nullable();        // que se busca, que se ofrece

            $table->date('opens_on')->nullable();
            $table->date('closes_on')->nullable();

            // Cuantos caben. Nulo cuando todavia no se sabe: inventar un numero
            // para poder guardar es peor que no tenerlo.
            $table->unsignedSmallInteger('slots')->nullable();

            // abierta · evaluada · cerrada
            $table->string('status', 16)->default('abierta');

            // Si acepta postulaciones desde el sitio. Apagada, la convocatoria
            // existe solo por dentro y la carga el equipo: sirve para preparar
            // el semestre antes de anunciarlo.
            $table->boolean('is_public')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'closes_on']);
        });

        Schema::create('internship_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_id')->constrained('internship_calls')->cascadeOnDelete();

            // ------------------------------------------------------ quien es
            $table->string('name', 160);
            $table->string('email', 160);
            $table->string('phone', 40)->nullable();
            $table->string('document_number', 40)->nullable();

            // ---------------------------------------------------- que estudia
            // La universidad de quien viene de fuera. Nula si es de la propia:
            // el correo institucional ya lo dice, y repetirlo invita a que las
            // dos versiones dejen de coincidir.
            $table->string('institution', 160)->nullable();
            $table->string('program', 160)->nullable();     // Diseno industrial
            $table->string('semester', 20)->nullable();     // 8 · ultimo

            // Lo que su universidad le exige cumplir. Es dato del convenio, no
            // un control de asistencia: aqui no se registran horas trabajadas.
            $table->unsignedSmallInteger('required_hours')->nullable();
            $table->string('availability', 160)->nullable();  // «mananas, lunes a jueves»

            // --------------------------------------------- por que y con que
            $table->text('motivation')->nullable();         // por que quiere entrar
            $table->string('portfolio_url')->nullable();

            // La hoja de vida: archivo en disco privado, o un enlace. Lo unico
            // que de verdad hace falta para poder evaluar.
            $table->string('cv_path')->nullable();
            $table->string('cv_url')->nullable();

            // web · equipo. De donde salio: lo que llega solo se revisa de otra
            // manera que lo que alguien del laboratorio anoto.
            $table->string('source', 12)->default('equipo');

            // Ley 1581 de 2012. Quien se postula desde el sitio lo autoriza en
            // el mismo acto; de lo que carga el equipo queda nulo hasta que
            // alguien lo confirme.
            $table->timestampTz('consent_at')->nullable();

            // ------------------------------------------------- la evaluacion
            // pendiente · espera · aceptado · descartado
            $table->string('status', 16)->default('pendiente');
            $table->unsignedTinyInteger('score')->nullable();   // 1 a 5: una nota, no un algoritmo
            $table->text('evaluation_note')->nullable();        // por que
            $table->text('fablab_note')->nullable();            // que podria hacer aqui
            $table->timestampTz('evaluated_at')->nullable();
            $table->foreignId('evaluated_by')->nullable()->constrained('users')->nullOnDelete();

            // La cuenta, cuando se acepta. Unica: una persona no se parte en
            // dos historiales.
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();

            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['call_id', 'status']);
            // Nadie se postula dos veces a la misma convocatoria: el segundo
            // envio corrige el primero en vez de duplicarlo.
            $table->unique(['call_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internship_applications');
        Schema::dropIfExists('internship_calls');
    }
};
