<?php

namespace App\Console\Commands;

use App\Services\Auth\MatrizDeAccesos;
use App\Support\Secciones;
use Illuminate\Console\Command;

/**
 * Pone al dia los permisos por seccion (§5).
 *
 * Corre en cada despliegue. Vivia dentro de una migracion, y eso fallaba de la
 * peor manera: una migracion corre UNA vez. Al añadir «crear, editar y borrar»
 * a la matriz, los permisos nuevos se crearon en desarrollo —donde la base se
 * rehace— y no en produccion, donde la migracion ya estaba dada por hecha. La
 * pantalla se veia bien y no guardaba.
 *
 * Un comando se puede volver a correr, que es justo lo que hace falta cada vez
 * que aparece una seccion o una accion nueva.
 */
class SincronizarAccesos extends Command
{
    protected $signature = 'fabos:accesos';

    protected $description = 'Crea los permisos de las secciones del panel sin tocar lo ya decidido';

    public function handle(MatrizDeAccesos $accesos): int
    {
        $accesos->sincronizar();

        $this->info(sprintf(
            '%d secciones · %d permisos al dia.',
            count(Secciones::todas()),
            count(Secciones::permisos()),
        ));

        return self::SUCCESS;
    }
}
