<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La demo: una hora en un espacio para ver funcionar sus máquinas, con los
 * archivos que se van a usar y alguien del equipo que la hace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->after('is_production');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('is_demo');
        });
    }
};
