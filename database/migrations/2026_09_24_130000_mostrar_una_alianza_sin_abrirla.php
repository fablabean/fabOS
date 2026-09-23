<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mostrar una alianza y recibir propuestas son dos decisiones (§11).
 *
 * Había un solo interruptor —«abierta a nuevos aliados en el sitio»— que hacía
 * las dos cosas a la vez: publicaba la alianza y abría el formulario para
 * pedir entrar. Así, enseñar lo que el laboratorio está construyendo obligaba
 * a aceptar que cualquiera se postulara, y no querer lo segundo dejaba el
 * proyecto invisible.
 *
 * Son cosas distintas. Una alianza ya cerrada —con sus partes completas— se
 * quiere seguir mostrando: es la prueba de lo que aquí se hace. Y una que se
 * está cocinando puede convenir que no se vea todavía aunque se busquen
 * aliados por otros medios.
 *
 * Quedan dos: **se muestra** y, además, **recibe propuestas**. Lo segundo no
 * tiene sentido sin lo primero —nadie se postula a lo que no puede ver— y el
 * formulario lo refleja.
 *
 * Las que ya estaban abiertas se quedan como estaban: mostrándose y
 * recibiendo. Nadie decidió lo contrario, y apagarles algo al desplegar sería
 * decidir por ellas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('alliance_public')->default(false)->after('alliance_open');
        });

        DB::table('projects')->where('alliance_open', true)->update(['alliance_public' => true]);
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('alliance_public');
        });
    }
};
