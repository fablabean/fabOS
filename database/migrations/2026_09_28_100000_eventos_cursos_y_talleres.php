<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eventos, cursos y talleres: todo el ciclo en un mismo sitio (§9).
 *
 *   publicar → inscribirse → requisitos → cupo o lista de espera → avisos →
 *   reprogramar o cancelar → asistencia por QR → encuesta → historial
 *
 * Se monta sobre la formación que ya existía, y no al lado: un taller es un
 * **curso** de otro tipo —con su nivel de la escalera, bit a tera—, cada fecha
 * o grupo es una **edición**, y quien se apunta es una **inscripción**. Lo
 * mismo que Fab Academy, que ya entraba por un formulario público sin cuenta.
 *
 * Lo que se decide una vez para la actividad —contenido, preguntas, encuesta—
 * vive en el curso, y así un grupo nuevo nace con todo. Lo que cambia de un
 * grupo a otro —fecha, lugar, cupo, público, precio— vive en la edición.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            // curso · taller · evento
            $table->string('kind', 12)->default('curso')->after('level');

            $table->string('banner_path')->nullable()->after('photo_path');
            $table->text('objectives')->nullable()->after('description');
            $table->text('recommendations')->nullable()->after('requirements');

            // Lo que incluye y lo que hay que llevar: son dos preguntas
            // distintas y la gente llega sin lo segundo si no se dice.
            $table->boolean('includes_materials')->default(false)->after('recommendations');
            $table->text('materials_included')->nullable()->after('includes_materials');
            $table->text('materials_to_bring')->nullable()->after('materials_included');

            // Imágenes, videos y material informativo: [{tipo, ruta|url, titulo}]
            $table->json('gallery')->nullable()->after('banner_path');

            // Lo que se acepta al inscribirse. Vacío: el texto por defecto.
            $table->text('registration_terms')->nullable()->after('materials_to_bring');
        });

        Schema::table('course_editions', function (Blueprint $table) {
            // El nombre del grupo, cuando hay varios: «Grupo sábados».
            $table->string('title', 120)->nullable()->after('code');

            $table->time('start_time')->nullable()->after('ends_on');
            $table->time('end_time')->nullable()->after('start_time');

            // Dónde, dicho como se dice. El espacio del laboratorio, si lo es,
            // va en space_id; esto cubre un auditorio o una sede de fuera.
            $table->string('location', 200)->nullable()->after('space_id');

            // ean · externos · ambos
            $table->string('audience', 12)->default('ambos')->after('capacity');

            $table->boolean('is_paid')->default(false)->after('audience');
            $table->unsignedBigInteger('price')->nullable()->after('is_paid');     // pesos
            $table->text('payment_info')->nullable()->after('price');

            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('survey_sent_at')->nullable();
        });

        Schema::table('enrollments', function (Blueprint $table) {
            // estudiante · profesor · colaborador · egresado · externo
            $table->string('participant_type', 20)->nullable()->after('status');
            $table->string('program', 160)->nullable()->after('participant_type');

            // Las respuestas a las preguntas de la actividad, por id, con la
            // pregunta copiada: si mañana se reescribe, lo respondido se sigue
            // leyendo como se preguntó.
            $table->json('answers')->nullable()->after('program');

            // web · equipo
            $table->string('source', 12)->default('equipo')->after('answers');
            $table->timestampTz('consent_at')->nullable()->after('source');

            $table->timestampTz('waitlisted_at')->nullable();
            $table->timestampTz('promoted_at')->nullable();
            $table->timestampTz('withdrawn_at')->nullable();
        });

        // Las preguntas del formulario de inscripción, además de las fijas.
        Schema::create('registration_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);

            // texto · parrafo · seleccion · multiple · aceptacion · archivo · numero · fecha · enlace
            $table->string('type', 16);
            $table->string('label', 300);
            $table->text('help')->nullable();
            $table->json('options')->nullable();
            $table->boolean('required')->default(false);

            // A quién se le pregunta. Nulo: a todos.
            $table->json('participant_types')->nullable();

            $table->timestamps();
        });

        // Cada cambio de una edición, con su motivo. Es lo que se contesta
        // cuando alguien pregunta «¿y por qué se movió?» tres meses después.
        Schema::create('course_edition_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_edition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // publicada · inscripciones_cerradas · reabierta · reprogramada ·
            // cancelada · novedad · cupo_asignado · encuesta
            $table->string('kind', 24);

            // sismo · cierre · falla_tecnica · espacio · ultimo_momento · otro
            $table->string('cause', 24)->nullable();
            $table->text('reason')->nullable();

            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->unsignedInteger('notified')->default(0);

            $table->timestamps();

            $table->index(['course_edition_id', 'created_at']);
        });

        // Las sesiones de una edición: cada una con su QR de asistencia.
        Schema::create('course_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_edition_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120)->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();

            // Lo que lleva el QR. No se adivina, y no dice de qué sesión es.
            $table->string('token', 40)->unique();

            $table->timestamps();

            $table->index(['course_edition_id', 'starts_at']);
        });

        Schema::create('session_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();

            // asistio · no_asistio
            $table->string('status', 12)->default('asistio');
            // qr · manual
            $table->string('method', 8)->default('qr');
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('marked_at');
            $table->string('note', 255)->nullable();

            $table->timestamps();

            // Escanear dos veces no cuenta dos veces.
            $table->unique(['course_session_id', 'enrollment_id']);
        });

        // La encuesta de después, definida en la actividad.
        Schema::create('survey_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);

            // escala · seleccion · si_no · texto
            $table->string('type', 12);
            $table->string('label', 300);
            $table->json('options')->nullable();
            $table->boolean('required')->default(true);

            $table->timestamps();
        });

        Schema::create('survey_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_edition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();

            // {id de la pregunta: {pregunta, tipo, valor}}
            $table->json('answers');
            $table->timestampTz('submitted_at');

            $table->timestamps();

            // Una respuesta por persona: el segundo envío no suma.
            $table->unique('enrollment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_responses');
        Schema::dropIfExists('survey_questions');
        Schema::dropIfExists('session_attendances');
        Schema::dropIfExists('course_sessions');
        Schema::dropIfExists('course_edition_changes');
        Schema::dropIfExists('registration_questions');

        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropColumn([
                'participant_type', 'program', 'answers', 'source', 'consent_at',
                'waitlisted_at', 'promoted_at', 'withdrawn_at',
            ]);
        });

        Schema::table('course_editions', function (Blueprint $table) {
            $table->dropColumn([
                'title', 'start_time', 'end_time', 'location', 'audience', 'is_paid', 'price',
                'payment_info', 'published_at', 'cancelled_at', 'survey_sent_at',
            ]);
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn([
                'kind', 'banner_path', 'objectives', 'recommendations', 'includes_materials',
                'materials_included', 'materials_to_bring', 'gallery', 'registration_terms',
            ]);
        });
    }
};
