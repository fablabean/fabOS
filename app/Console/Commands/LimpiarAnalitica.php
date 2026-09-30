<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Borra lo crudo de la analítica con más de 13 meses (§20).
 *
 * Trece y no doce: así siempre se puede comparar un mes con el mismo mes del
 * año anterior, entero.
 */
class LimpiarAnalitica extends Command
{
    protected $signature = 'fabos:limpiar-analitica {--meses=13}';

    protected $description = 'Borra las visitas, eventos y rastreos de la analítica más viejos que el plazo';

    public function handle(): int
    {
        $limite = now(config('fabos.lab.timezone'))->subMonths((int) $this->option('meses'))->toDateString();

        $borrados = 0;

        foreach (['analitica_visitas', 'analitica_eventos', 'analitica_rastreos'] as $tabla) {
            $borrados += DB::table($tabla)->where('dia', '<', $limite)->delete();
        }

        $this->info("Borrados {$borrados} registros anteriores al {$limite}.");

        return self::SUCCESS;
    }
}
