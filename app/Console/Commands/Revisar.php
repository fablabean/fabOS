<?php

namespace App\Console\Commands;

use App\Services\Install\ReadinessService;
use Illuminate\Console\Command;

/**
 * Revisión previa al despliegue (§18).
 *
 * Se corre en el servidor, antes de abrirle el sistema a la gente. Devuelve
 * código de salida distinto de cero si hay algo que bloquea, para poder
 * encadenarlo en un script de despliegue y que se detenga solo.
 */
class Revisar extends Command
{
    protected $signature = 'fabos:revisar {--todo : Mostrar también lo que está bien}';

    protected $description = 'Revisa si esta instancia está lista para producción';

    public function handle(ReadinessService $revision): int
    {
        $this->info('fabOS · revisión previa al despliegue');
        $this->line(config('fabos.lab.name') . ' · ' . config('app.url'));
        $this->newLine();

        $resultados = $revision->revisar();
        $graves = $resultados->where('nivel', ReadinessService::GRAVE);
        $avisos = $resultados->where('nivel', ReadinessService::AVISO);

        foreach ($resultados as $r) {
            if ($r['nivel'] === ReadinessService::BIEN && ! $this->option('todo')) {
                continue;
            }

            $marca = match ($r['nivel']) {
                ReadinessService::GRAVE => '<fg=red>  ✗</>',
                ReadinessService::AVISO => '<fg=yellow>  !</>',
                default                 => '<fg=green>  ✓</>',
            };

            $this->line($marca . ' ' . $r['titulo']);

            if ($r['nivel'] !== ReadinessService::BIEN) {
                $this->line('      ' . $r['detalle']);

                if ($r['arreglo']) {
                    $this->line('      <fg=cyan>→ ' . $r['arreglo'] . '</>');
                }

                $this->newLine();
            }
        }

        $this->newLine();

        if ($graves->isNotEmpty()) {
            $this->error($graves->count() . ' cosa(s) impiden desplegar a producción.');

            return self::FAILURE;
        }

        if ($avisos->isNotEmpty()) {
            $this->warn($avisos->count() . ' aviso(s). Se puede desplegar, pero conviene mirarlos.');

            return self::SUCCESS;
        }

        $this->info('Todo en orden.');

        return self::SUCCESS;
    }
}
