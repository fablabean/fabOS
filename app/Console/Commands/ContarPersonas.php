<?php

namespace App\Console\Commands;

use App\Services\Camaras\ConteoDePersonas;
use App\Services\Vision\Pgx;
use App\Support\Camaras;
use Illuminate\Console\Command;
use Throwable;

/**
 * Un cuadro de cada cámara al PGX, y el conteo del día al día.
 *
 * Corre cada minuto solo si `CONTEO_DISPARO=minuto`. Lo normal es que lo
 * dispare la propia cámara al ver personas (fabos:escuchar-camaras).
 *
 * El cuadro completo no se guarda: va de la cámara al PGX en memoria, y solo
 * queda el recorte de cada persona contada, hasta el cierre del día. Si
 * una cámara o el PGX no responden, se anota el error para la pantalla y se
 * sigue con la siguiente; el minuto que viene se vuelve a intentar.
 */
class ContarPersonas extends Command
{
    protected $signature = 'fabos:contar-personas {--cerrar-dia : olvida a quien no ha venido en los últimos días}';

    protected $description = 'Cuenta las personas que ven las cámaras, con el PGX';

    public function handle(ConteoDePersonas $conteo): int
    {
        if ($this->option('cerrar-dia')) {
            $borradas = $conteo->cerrarDiasPasados(now());
            $this->info("Personas olvidadas: {$borradas}");

            return self::SUCCESS;
        }

        if (! config('fabos.conteo.activo') || ! Pgx::configurado()) {
            return self::SUCCESS;
        }

        foreach (Camaras::todas() as $camara) {
            try {
                $lectura = $conteo->fotografiar($camara['numero']);
                $this->line("{$camara['nombre']}: {$lectura['personas']} personas, {$lectura['nuevas']} nuevas hoy");
            } catch (Throwable $e) {
                $this->warn("{$camara['nombre']}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
