<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que vale una alianza allá afuera (§11).
 *
 * Un servicio tiene un valor estimado y uno acordado: lo que cobraríamos y lo
 * que se firmó. Una alianza no tiene cliente que pague, así que esos dos
 * campos no dicen nada de ella —y sin embargo se sumaban en el embudo como si
 * fueran venta—.
 *
 * Lo que sí dice algo es cuánto vale el proyecto en el mercado, y qué parte de
 * eso es nuestra según la participación pactada. Es un campo propio y no el
 * estimado reutilizado: son dos preguntas distintas —lo que cobraríamos por
 * hacer el trabajo, y lo que vale el negocio— y el día que un proyecto fuera
 * las dos cosas, un solo número tendría que mentir en una.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->bigInteger('market_value')->default(0)->after('estimated_value');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('market_value');
        });
    }
};
