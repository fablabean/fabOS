<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El calendario de fuera de cada persona (§8).
 *
 * fabOS sabe de la jornada y de lo que ya se reservo aqui, pero no de las
 * clases ni de las reuniones: por eso ofrecia franjas de asesoria a las que
 * quien asesora no podia ir. La salida sin credenciales de nadie es que cada
 * persona publique su calendario de Outlook y pegue aqui la direccion.
 *
 * Es un SECRETO: quien la tenga lee la agenda de esa persona. Por eso no sale
 * en los listados ni se escribe en los registros.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('external_calendar_url')->nullable()->after('calendar_token');
            $table->timestamp('external_calendar_synced_at')->nullable()->after('external_calendar_url');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['external_calendar_url', 'external_calendar_synced_at']);
        });
    }
};
