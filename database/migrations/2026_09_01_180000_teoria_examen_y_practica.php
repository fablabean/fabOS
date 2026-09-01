<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un curso con teoria, examen y evaluacion presencial (§9, §10).
 *
 * Hasta ahora un curso era una edicion con fechas y alguien que marcaba
 * «aprobado» a mano. Funciona para un taller, pero no para lo que habilita a
 * usar una maquina sin nadie al lado: el certifab dice que esa persona sabe, y
 * con «asistio» no se sabe si sabe.
 *
 * Tres pasos, y los tres importan por separado:
 *
 *  1. LA TEORIA se lee cuando se pueda. Es lo unico que no exige coincidir con
 *     nadie, asi que no deberia esperar a que se abra un cupo.
 *  2. EL EXAMEN corrige solo. Sin eso, cada aprobado depende de quien tuviera
 *     tiempo de revisarlo.
 *  3. LA PRACTICA la firma una persona, delante de la maquina. Una pantalla no
 *     puede ver si alguien nivela una cama.
 */
return new class extends Migration
{
    public function up(): void
    {
        // La teoria: pantallas cortas, en orden.
        Schema::create('course_lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('title');
            $table->text('body');
            $table->timestamps();

            $table->index(['course_id', 'position']);
        });

        // El examen: preguntas de opcion multiple, que son las que se pueden
        // corregir sin una persona delante.
        Schema::create('course_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->text('prompt');
            $table->json('options');
            $table->unsignedSmallInteger('correct');
            // Por que esa es la buena. Se enseña al corregir: un examen que
            // solo dice «mal» enseña a adivinar, no a operar la maquina.
            $table->text('explanation')->nullable();
            $table->timestamps();

            $table->index(['course_id', 'position']);
        });

        Schema::table('courses', function (Blueprint $table) {
            // Cuanto hay que sacar. Por curso y no global: no es lo mismo un
            // primer contacto que lo que habilita a usar una laser solo.
            $table->unsignedSmallInteger('passing_score')->default(80)->after('hours');
            /*
             * Si hace falta la practica. APAGADO por defecto, y a proposito.
             *
             * Encendido, los cursos que ya existen empezarian a exigir una
             * evaluacion presencial que nadie acordo, y sus inscripciones se
             * quedarian sin poder aprobarse hasta que alguien descubriera por
             * que. Lo nuevo se enciende curso por curso, mirandolo.
             */
            $table->boolean('requires_practical')->default(false)->after('passing_score');
        });

        Schema::table('enrollments', function (Blueprint $table) {
            $table->unsignedSmallInteger('theory_score')->nullable()->after('grade');
            $table->timestamp('theory_passed_at')->nullable()->after('theory_score');
            $table->unsignedTinyInteger('theory_attempts')->default(0)->after('theory_passed_at');

            $table->timestamp('practical_passed_at')->nullable()->after('theory_attempts');
            $table->foreignId('practical_by')->nullable()->after('practical_passed_at')
                ->constrained('users')->nullOnDelete();
            $table->text('practical_notes')->nullable()->after('practical_by');
        });

        // Una edicion puede no tener fechas: la teoria se lee cuando se pueda,
        // y lo unico que exige coincidir es la practica.
        Schema::table('course_editions', function (Blueprint $table) {
            $table->boolean('is_self_paced')->default(false)->after('capacity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_questions');
        Schema::dropIfExists('course_lessons');

        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn(['passing_score', 'requires_practical']);
        });

        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('practical_by');
            $table->dropColumn([
                'theory_score', 'theory_passed_at', 'theory_attempts',
                'practical_passed_at', 'practical_notes',
            ]);
        });

        Schema::table('course_editions', function (Blueprint $table) {
            $table->dropColumn('is_self_paced');
        });
    }
};
