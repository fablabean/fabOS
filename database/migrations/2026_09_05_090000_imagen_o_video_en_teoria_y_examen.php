<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una imagen o un video en cada pantalla de teoria y en cada pregunta (§9).
 *
 * «Nivelar la cama» en texto se entiende a medias; con la foto de la cama y
 * la hoja de papel debajo de la boquilla, se entiende. Y una pregunta sobre
 * una pieza mal impresa necesita la foto de la pieza: sin ella, la pregunta
 * es sobre lo que uno se imagina.
 *
 * Dos columnas, y no una: el fichero que se sube -foto o video corto- y el
 * enlace a un video que ya esta en YouTube o Vimeo, que es donde suelen
 * estar los tutoriales de una maquina. Se puede poner uno, el otro o los dos.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['course_lessons', 'course_questions'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->string('media_path')->nullable();
                $table->string('video_url')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['course_lessons', 'course_questions'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropColumn(['media_path', 'video_url']);
            });
        }
    }
};
