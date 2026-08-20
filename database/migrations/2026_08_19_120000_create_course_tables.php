<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Formación (§9).
 *
 * Tres piezas que conviene no confundir:
 *
 *  - **Curso**: lo que se enseña. Existe una vez y se dicta muchas.
 *  - **Edición**: una cohorte concreta, con fechas, cupo, instructor y espacio.
 *    Es lo que se inscribe y lo que se cierra.
 *  - **Inscripción**: la relación de una persona con una edición.
 *
 * Y la pieza que cierra el círculo con el resto del sistema: **aprobar una
 * edición otorga certifabs**. Hasta ahora la única forma de habilitarse era una
 * asesoría uno a uno; esto convierte la formación en la vía natural, que es lo
 * que hace escalar un laboratorio. El nivel del curso sigue siendo
 * prerrequisito, no habilitación: lo que abre la reserva es el certifab.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();

            // bit | byte | kilo | mega | giga | tera. tera es Fab Academy.
            $table->string('level')->default('bit');

            $table->text('summary')->nullable();          // para el catálogo público
            $table->text('description')->nullable();
            $table->text('requirements')->nullable();     // qué hace falta para entrar

            $table->unsignedSmallInteger('hours')->nullable();
            $table->string('photo_path')->nullable();

            // Costo en FabCoins. Cero = sin costo para la comunidad.
            $table->bigInteger('price_minor')->default(0);

            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true);
            $table->timestamps();
        });

        // Qué habilita aprobar el curso. Sin esto, un curso sería solo una
        // charla: es la fila que convierte formación en permiso de uso.
        Schema::create('course_risk_family', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('risk_family_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['course_id', 'risk_family_id']);
        });

        Schema::create('course_editions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('code')->unique();                 // FOR-2026-0001

            $table->foreignId('instructor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('space_id')->nullable()->constrained()->nullOnDelete();

            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('schedule_note')->nullable();      // «martes 14:00 a 17:00»

            $table->unsignedSmallInteger('capacity')->default(12);

            // planeada | abierta | en_curso | cerrada | cancelada
            $table->string('status')->default('planeada');
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['status', 'starts_on']);
        });

        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_edition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // inscrito | aprobado | reprobado | retirado
            $table->string('status')->default('inscrito');

            $table->decimal('grade', 4, 2)->nullable();
            $table->text('feedback')->nullable();

            // El certificado se emite al aprobar y se verifica en público, igual
            // que un certifab: el código es lo que le sirve a la persona fuera
            // del sistema.
            $table->string('certificate_code')->nullable()->unique();
            $table->timestampTz('completed_at')->nullable();

            $table->timestampTz('enrolled_at')->useCurrent();
            $table->timestamps();

            // Nadie se inscribe dos veces en la misma edición.
            $table->unique(['course_edition_id', 'user_id']);
        });

        DB::statement("
            ALTER TABLE enrollments
            ADD CONSTRAINT enrollments_nota_valida
            CHECK (grade IS NULL OR (grade >= 0 AND grade <= 5))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollments');
        Schema::dropIfExists('course_editions');
        Schema::dropIfExists('course_risk_family');
        Schema::dropIfExists('courses');
    }
};
