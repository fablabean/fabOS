<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que pide el sistema de calidad (ISO 9001, proceso INV-200).
 *
 * - En el activo, lo del procedimiento INV-118: el esquema de color con que se
 *   revisa (verde, amarillo, rojo) y si es un equipo de apoyo del Anexo 2, que
 *   no lleva hoja de vida. Más los accesorios y las observaciones del formato
 *   INV-113-f1, que hasta ahora no tenían dónde anotarse.
 * - En el acta, lo que el formato ADF-207-f3 de baja pide y el de movimiento
 *   no: el motivo, quién firma por la Vicerrectoría y de qué proceso sale.
 * - Las auditorías, internas y externas, con sus hallazgos y mejoras.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('esquema_color', 12)->nullable()->after('kind');
            $table->boolean('es_de_apoyo')->default(false)->after('esquema_color');
            $table->json('accesorios')->nullable();
            $table->text('observaciones')->nullable();
        });

        Schema::table('actas', function (Blueprint $table) {
            $table->string('baja_motivo', 30)->nullable();
            $table->string('baja_vicerrector')->nullable();
            $table->string('baja_responsable')->nullable();
            $table->string('baja_responsable_cargo')->nullable();
            $table->string('baja_proceso')->nullable();
        });

        Schema::create('auditorias', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 10);
            $table->string('titulo');
            $table->dateTime('realizada_at');
            $table->string('auditor')->nullable();
            $table->string('proceso')->nullable();
            $table->text('alcance')->nullable();
            $table->string('resultado', 30)->default('pendiente');
            $table->text('resumen')->nullable();
            $table->json('hallazgos')->nullable();
            $table->string('informe_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auditorias');

        Schema::table('actas', function (Blueprint $table) {
            $table->dropColumn(['baja_motivo', 'baja_vicerrector', 'baja_responsable', 'baja_responsable_cargo', 'baja_proceso']);
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn(['esquema_color', 'es_de_apoyo', 'accesorios', 'observaciones']);
        });
    }
};
