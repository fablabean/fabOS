<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Se retiran los encargos (§14).
 *
 * La idea era que alguien pidiera un trabajo hecho por el equipo, se le
 * cotizara y se produjera. En la práctica no se usó: lo que llega al
 * laboratorio o es una reserva —la persona opera la máquina— o es un proyecto
 * —hay alcance, entregables y alguien que responde—. El encargo se quedó en
 * medio, sin ser ninguna de las dos, y una entrada de menú que nadie abre le
 * quita sitio a las que sí.
 *
 * Se retira con la tabla vacía y a propósito, no por descuido: el código de un
 * módulo muerto envejece peor que el de uno vivo, porque nadie lo lee al
 * cambiar lo de al lado.
 *
 * Lo que sí sigue: la lógica de «llega algo, se evalúa, se convierte en
 * trabajo» se reaprovecha en los **lotes de candidatos**, que es lo que el
 * laboratorio necesita de verdad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('production_jobs');
    }

    public function down(): void
    {
        // No se recrea: el modulo se retiro entero, y una tabla sin modelo ni
        // pantallas seria solo un sitio donde nada puede entrar.
    }
};
