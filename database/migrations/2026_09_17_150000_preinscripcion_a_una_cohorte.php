<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Preinscribirse a una cohorte que todavía no se sabe si abre (§9).
 *
 * Fab Academy no es un curso que se elige de una lista. Son seis meses, cuesta
 * miles de dólares y el laboratorio solo puede abrir la cohorte si se junta
 * gente suficiente: la pregunta no es «¿hay cupo?» sino «¿hay cohorte?». Hasta
 * ahora esa pregunta se contestaba con un «escríbenos y te avisamos», y los
 * interesados quedaban repartidos entre correos y conversaciones de pasillo.
 * Cuando llegaba la hora de decidir, nadie sabía cuántos eran.
 *
 * **La preinscripción cuelga de la cohorte planeada, no del curso.** «Fab
 * Academy 2027» es una edición en estado `planeada`, con su fecha, su cupo y
 * —lo nuevo— **cuántos hacen falta para abrirla**. Quien se preinscribe lo hace
 * a esa cohorte concreta; el interés del año pasado no se cuenta para este.
 *
 * **Un preinscrito no es una cuenta ni un inscrito.** No ocupa cupo y no tiene
 * usuario: es alguien que dijo «si se abre, voy». Pasa a ser cuenta e
 * inscripción cuando la cohorte abre y el equipo lo inscribe, que es el mismo
 * criterio de las convocatorias de práctica (§5).
 *
 * **Qué curso funciona así lo dice el curso**, con una casilla. Hoy es solo
 * tera, pero nada de esto lleva «Fab Academy» escrito: un diplomado que
 * dependa de juntar gente entra por la misma puerta.
 *
 * Datos personales (Ley 1581 de 2012): quien se preinscribe desde el sitio
 * autoriza el tratamiento en el mismo acto, y queda con su fecha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            // Se entra preinscribiendose a una cohorte planeada, y no eligiendo
            // una edicion abierta de la lista.
            $table->boolean('by_preenrollment')->default(false)->after('is_public');
        });

        Schema::table('course_editions', function (Blueprint $table) {
            // Cuantos hacen falta para que la cohorte abra. Nulo cuando nadie
            // lo ha decidido: inventar un numero para poder guardar es peor
            // que no tenerlo, y la pagina publica simplemente no lo promete.
            $table->unsignedSmallInteger('minimum_to_open')->nullable()->after('capacity');

            // Hasta cuando se reciben preinscripciones. Fecha de calendario.
            $table->date('preenroll_until')->nullable()->after('minimum_to_open');

            // El costo, dicho como se dice: «3800 USD, en cuotas». El precio
            // del curso va en la moneda del laboratorio y Fab Academy se paga
            // en dolares a dos entidades; un numero solo no lo cuenta.
            $table->string('price_note', 200)->nullable()->after('preenroll_until');
        });

        Schema::create('preenrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_edition_id')->constrained('course_editions')->cascadeOnDelete();

            // ------------------------------------------------------ quien es
            $table->string('name', 160);
            $table->string('email', 160);
            $table->string('phone', 40)->nullable();
            $table->string('city', 120)->nullable();          // el nodo es Bogota; importa de donde viene
            $table->string('occupation', 160)->nullable();    // a que se dedica
            $table->string('institution', 160)->nullable();   // empresa o universidad, si viene de una

            // --------------------------------------------- por que y con que
            $table->text('motivation')->nullable();
            $table->string('portfolio_url')->nullable();

            // propio · empresa · institucion · beca · no_se. Es lo que decide
            // si una cohorte es viable: diez interesados que esperan una beca
            // que no existe no son diez estudiantes.
            $table->string('funding', 16)->nullable();

            // web · equipo
            $table->string('source', 12)->default('equipo');

            // Ley 1581 de 2012. De lo que carga el equipo queda nulo.
            $table->timestampTz('consent_at')->nullable();

            // preinscrito · confirmado · inscrito · desistio
            $table->string('status', 16)->default('preinscrito');
            $table->timestampTz('confirmed_at')->nullable();

            // La cuenta, si ya la tenia al preinscribirse o cuando se le crea
            // al inscribirlo. NO es unica: la misma persona puede preinscribirse
            // a la cohorte de este año y a la del siguiente.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // En que acabo: la inscripcion de verdad, cuando la cohorte abrio.
            $table->foreignId('enrollment_id')->nullable()->constrained('enrollments')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['course_edition_id', 'status']);
            // Nadie se preinscribe dos veces a la misma cohorte: el segundo
            // envio corrige el primero en vez de duplicarlo.
            $table->unique(['course_edition_id', 'email']);
        });

        // El curso sembrado de Fab Academy entra por aqui desde el primer dia.
        // Solo marca la casilla: prenderlo y planear la cohorte es decision de
        // quien coordina, y se hace desde el panel.
        DB::table('courses')->where('slug', 'tera-fab-academy')->update(['by_preenrollment' => true]);
    }

    public function down(): void
    {
        Schema::dropIfExists('preenrollments');

        Schema::table('course_editions', function (Blueprint $table) {
            $table->dropColumn(['minimum_to_open', 'preenroll_until', 'price_note']);
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('by_preenrollment');
        });
    }
};
