<?php

namespace App\Console\Commands;

use App\Models\Bloqueo;
use App\Services\Booking\Festivos;
use Illuminate\Console\Command;

/**
 * Pone el bloqueo de cada festivo que viene (App\Services\Booking\Festivos).
 *
 * Corre cada día y mira dos meses adelante: el bloqueo existe con tiempo para
 * avisarse en el sitio, y para que alguien lo levante si ese festivo se abre.
 */
class BloquearFestivos extends Command
{
    protected $signature = 'fabos:bloquear-festivos {--dias=60 : Cuántos días hacia adelante}';

    protected $description = 'Bloquea las reservas en los festivos que vienen';

    public function handle(Festivos $festivos): int
    {
        $nuevos = $festivos->bloquear((int) $this->option('dias'));

        if ($nuevos->isEmpty()) {
            $this->info('Los festivos que vienen ya tienen su bloqueo.');

            return self::SUCCESS;
        }

        foreach ($nuevos as $b) {
            /** @var Bloqueo $b */
            $this->line('Bloqueado ' . $b->cuando() . '.');
        }

        return self::SUCCESS;
    }
}
