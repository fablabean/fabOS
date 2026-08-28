<?php

use App\Services\Auth\MatrizDeAccesos;
use Illuminate\Database\Migrations\Migration;

/**
 * Los permisos por seccion, y el rol practicante (§5).
 *
 * No crea tablas: las de Spatie ya existen. Siembra los permisos `ver.*` de
 * cada seccion del panel y reparte lo que cada rol veia hasta ahora, para que
 * encender la matriz no cambie nada de golpe.
 *
 * Va en una migracion y no en un seeder porque el despliegue migra pero no
 * siembra: un permiso que solo existiera en un seeder dejaria el panel vacio
 * en produccion para todos menos el superadmin.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(MatrizDeAccesos::class)->sincronizar();
    }

    public function down(): void
    {
        // Los permisos se quedan. Borrarlos dejaria a los roles sin nada y el
        // panel vacio, que es peor que sobrarle unas filas a una tabla.
    }
};
