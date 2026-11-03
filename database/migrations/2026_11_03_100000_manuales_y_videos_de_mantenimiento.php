<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que se consulta para hacerle mantenimiento a un equipo (§8).
 *
 * El video de la ficha es el de la vitrina: muestra la máquina, no enseña a
 * repararla. Los manuales no siempre están a mano, y lo que de verdad sirvió
 * muchas veces fue un video que alguien encontró a mitad de la reparación.
 *
 * - `assets.maintenance_resources`: manuales en PDF y enlaces a videos o
 *   páginas, cada uno con su título. Se ve desde la orden y desde el QR.
 * - `work_orders.reference_videos`: los videos que se usaron en esa orden,
 *   como parte de su evidencia. Si sirven para la próxima, pasan a la ficha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->json('maintenance_resources')->nullable()->after('video_url');
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->json('reference_videos')->nullable()->after('photos');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn('reference_videos');
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn('maintenance_resources');
        });
    }
};
