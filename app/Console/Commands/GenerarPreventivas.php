<?php

namespace App\Console\Commands;

use App\Services\Maintenance\MaintenanceService;
use Illuminate\Console\Command;

/**
 * Convierte los planes preventivos en órdenes de trabajo reales.
 *
 * Un plan que nadie ejecuta es una intención. Esto es lo que lo pone en la
 * bandeja del técnico el día que toca.
 */
class GenerarPreventivas extends Command
{
    protected $signature = 'fabos:generar-preventivas';

    protected $description = 'Crea las órdenes preventivas que ya tocan';

    public function handle(MaintenanceService $mantenimiento): int
    {
        $n = $mantenimiento->generarPreventivas();

        $this->info($n === 0 ? 'No hay preventivos pendientes.' : "Generadas {$n} órdenes preventivas.");

        return self::SUCCESS;
    }
}
